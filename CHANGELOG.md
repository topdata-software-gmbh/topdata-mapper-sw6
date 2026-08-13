# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed
- Replaced the skeleton example controllers/command with the mapping engine:
  mapping tables (`tdmp_product`, `tdmp_brand`), the mapping-API webservice
  client, the local identifier matcher, and the `topdata:mapper:import` command.
- The import command now prints a summary table (pages, API rows, matched,
  unmatched, duration) at the end of the run.

## [1.0.0] - 2026-08-13

### Added
- Initial release
