# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab KEGG: a compound (C07481), a drug (D00528), a reaction (R00200), a pathway map (map00010,
  hsa00010) or an enzyme (2.7.1.40) by its KEGG identifier - names, formula and masses, a reaction's
  equation, a pathway's class and description, an enzyme's class and systematic name - the same
  thing in CAS, ChEBI, ChEMBL and Rhea as identifiers, its reactions, pathways, enzymes, compounds
  and GO terms as related things; a compound by its ChEBI id; a search; through KEGG's REST API
  (rest.kegg.jp), for academic use by academic users only.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/kegg` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
