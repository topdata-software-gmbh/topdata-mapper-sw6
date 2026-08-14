<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Controller\Api;

use Shopware\Core\Framework\Api\Acl\AclService;
use Shopware\Core\Framework\Api\Exception\MissingPrivilegeException;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Topdata\TopdataMapperSW6\Service\Db\TdmpConflictResolutionService;
use Topdata\TopdataMapperSW6\Service\Dsl\DslOrExpr;
use Topdata\TopdataMapperSW6\Service\Dsl\DslPairingMatrix;
use Topdata\TopdataMapperSW6\Service\Dsl\DslParseException;
use Topdata\TopdataMapperSW6\Service\Dsl\DslParser;
use Topdata\TopdataMapperSW6\Service\Dsl\DslSerializer;
use Topdata\TopdataMapperSW6\Service\DslStrategyService;
use Topdata\TopdataMapperSW6\Service\TopdataMapperWebserviceV2Client;

/**
 * Action routes for the mapper admin modules (settings + conflicts).
 *
 * The settings page NEVER writes config storage directly — POST strategy is
 * the authoritative write gate (grammar + pairing matrix + provider-id
 * existence validated before any SystemConfigService write). The import
 * backstop re-validates the stored strategy per run and fails loudly.
 *
 * Privileges: topdata_mapper:read (view) / topdata_mapper:update (write),
 * declared in Resources/config/acl.xml.
 *
 * 08/2026 created
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class TopdataMapperActionController extends AbstractController
{
    private const int PROVIDER_LIST_LIMIT = 100000;

    public function __construct(
        private readonly DslStrategyService              $strategyService,
        private readonly DslParser                        $dslParser,
        private readonly DslSerializer                    $dslSerializer,
        private readonly TdmpConflictResolutionService    $conflictResolutionService,
        private readonly TopdataMapperWebserviceV2Client  $mapperClient,
        private readonly AclService                       $aclService,
    ) {
    }

    /**
     * Module init for the settings page: current DSL + presets + pairing
     * matrix + credential status + the user's reserved providers (one round
     * trip; provider list is skipped when the webservice is unreachable).
     */
    #[Route(path: '/api/_action/topdata-mapper/strategy', name: 'api.action.topdata-mapper.strategy.get', methods: ['GET'])]
    public function getStrategyAction(Context $context): JsonResponse
    {
        $this->_assertPrivilege('topdata_mapper:read', $context);

        return new JsonResponse([
            'dsl'                   => $this->strategyService->getConfiguredDsl(),
            'presets'               => $this->strategyService->getPresets(),
            'allowedPairs'          => DslPairingMatrix::toArray(),
            'credentialsConfigured' => $this->mapperClient->hasValidConfig(),
            'providers'             => $this->_fetchProviders(),
        ]);
    }

    /**
     * Authoritative strategy write gate: full validation (grammar + pairing
     * matrix + provider-id existence) BEFORE any config write. Violations →
     * HTTP 400 with structured error (shop field, dimension, position) and
     * nothing persisted. The stored value is the canonical serializer output.
     */
    #[Route(path: '/api/_action/topdata-mapper/strategy', name: 'api.action.topdata-mapper.strategy.save', methods: ['POST'])]
    public function saveStrategyAction(Request $request, Context $context): JsonResponse
    {
        $this->_assertPrivilege('topdata_mapper:update', $context);

        $dsl = (string)($request->request->all()['dsl'] ?? '');
        try {
            $ast = $this->dslParser->parse($dsl);
            $this->_assertProvidersExist($ast, $dsl);
        } catch (DslParseException $e) {
            return new JsonResponse(['error' => $e->toArray()], Response::HTTP_BAD_REQUEST);
        }

        $this->strategyService->save($dsl);

        return new JsonResponse(['dsl' => $this->dslSerializer->toString($ast)]);
    }

    /**
     * Debounced live validation while typing: {valid, ast, error}. Grammar +
     * pairing matrix only (no provider check — the provider scope is validated
     * on save and re-validated by the import backstop).
     */
    #[Route(path: '/api/_action/topdata-mapper/validate-strategy', name: 'api.action.topdata-mapper.strategy.validate', methods: ['POST'])]
    public function validateStrategyAction(Request $request, Context $context): JsonResponse
    {
        $this->_assertPrivilege('topdata_mapper:read', $context);

        $dsl = (string)($request->request->all()['dsl'] ?? '');
        try {
            $ast = $this->dslParser->parse($dsl);

            return new JsonResponse(['valid' => true, 'ast' => $this->dslSerializer->toArray($ast), 'error' => null]);
        } catch (DslParseException $e) {
            return new JsonResponse(['valid' => false, 'ast' => null, 'error' => $e->toArray()]);
        }
    }

    /**
     * Server-side paginated/filtered conflict list for the sw-data-grid
     * (page/limit/status/search). Conflicts can be numerous (lazy strategy
     * across a big catalog), so all data access is via this action route.
     */
    #[Route(path: '/api/_action/topdata-mapper/conflicts', name: 'api.action.topdata-mapper.conflicts.list', methods: ['GET'])]
    public function listConflictsAction(Request $request, Context $context): JsonResponse
    {
        $this->_assertPrivilege('topdata_mapper:read', $context);

        $page   = max(1, (int)$request->get('page', 1));
        $limit  = min(max(1, (int)$request->get('limit', 25)), 500);
        $status = $request->get('status');
        $search = $request->get('search');

        $result = $this->conflictResolutionService->listConflicts(
            $page,
            $limit,
            is_string($status) && $status !== '' ? $status : null,
            is_string($search) ? $search : null
        );

        return new JsonResponse([
            'rows'  => $result['rows'],
            'total' => $result['total'],
            'page'  => $page,
            'limit' => $limit,
            'stats' => $this->conflictResolutionService->getStats(),
        ]);
    }

    /**
     * Resolves a conflict without re-import: marks the resolution row 'user'
     * and points the product's tdmp_product row at the chosen article —
     * effective immediately.
     */
    #[Route(path: '/api/_action/topdata-mapper/resolve-conflict', name: 'api.action.topdata-mapper.conflicts.resolve', methods: ['POST'])]
    public function resolveConflictAction(Request $request, Context $context): JsonResponse
    {
        $this->_assertPrivilege('topdata_mapper:update', $context);

        $body     = $request->request->all();
        $productId = (string)($body['productId'] ?? '');
        $chosen   = (int)($body['chosenTopdataProductId'] ?? 0);

        if ($productId === '' || !preg_match('/^[0-9a-f]{32}$/', $productId)) {
            return new JsonResponse(['error' => 'productId must be a 32-char lowercase hex product id.'], Response::HTTP_BAD_REQUEST);
        }
        if ($chosen <= 0) {
            return new JsonResponse(['error' => 'chosenTopdataProductId must be a positive Topdata product id.'], Response::HTTP_BAD_REQUEST);
        }

        $resolutions = $this->conflictResolutionService->loadResolutions();
        if (!isset($resolutions[$productId])) {
            return new JsonResponse(['error' => 'No conflict resolution for this product.'], Response::HTTP_NOT_FOUND);
        }

        $candidateIds = array_map(fn (array $c) => $c['id'], $resolutions[$productId]['candidates']);
        if (!in_array($chosen, $candidateIds, true)) {
            return new JsonResponse(['error' => 'chosenTopdataProductId is not among the conflict candidates.'], Response::HTTP_BAD_REQUEST);
        }

        $this->conflictResolutionService->applyUserResolution($productId, $chosen);

        return new JsonResponse(['success' => true]);
    }

    /**
     * @throws MissingPrivilegeException
     */
    private function _assertPrivilege(string $privilege, Context $context): void
    {
        $this->aclService->validate([$privilege], $context);
    }

    /**
     * Reserved providers of the webservice user (for the articleNumbers.<id>
     * provider dropdown). Best effort: unreachable/not configured → [] so the
     * settings page still loads.
     *
     * @return list<array{id: int, name: string}>
     */
    private function _fetchProviders(): array
    {
        if (!$this->mapperClient->hasValidConfig()) {
            return [];
        }

        try {
            $response = $this->mapperClient->getProviders(0, self::PROVIDER_LIST_LIMIT, 'de');
        } catch (\Throwable $e) {
            return [];
        }

        $providers = [];
        foreach ($response->rows ?? [] as $row) {
            $providers[] = [
                'id'   => (int)$row->id,
                'name' => (string)$row->name,
            ];
        }

        return $providers;
    }

    /**
     * Checks that every articleNumbers.<provider> reference in the strategy
     * exists among the user's reserved providers. Best effort: when the
     * webservice is not configured/unreachable the check is skipped (the
     * import backstop fails loudly on real credential problems).
     */
    private function _assertProvidersExist(DslOrExpr $ast, string $dsl): void
    {
        $providerIds = [];
        foreach ($this->_fetchProviders() as $provider) {
            $providerIds[$provider['id']] = true;
        }
        if (empty($providerIds)) {
            return;
        }

        foreach ($ast->groups as $group) {
            foreach ($group->leaves as $leaf) {
                if ($leaf->dimensionVariant === null) {
                    continue;
                }
                $providerId = (int)$leaf->dimensionVariant;
                if (!isset($providerIds[$providerId])) {
                    throw new DslParseException(
                        "Unknown provider id '{$leaf->dimensionVariant}' in articleNumbers.<provider> — not among your reserved providers.",
                        shopField: $leaf->shopField,
                        dimension: 'articleNumbers.' . $leaf->dimensionVariant,
                        position: strpos($dsl, $leaf->shopField . ':' . $leaf->dimension . '.' . $leaf->dimensionVariant)
                    );
                }
            }
        }
    }
}