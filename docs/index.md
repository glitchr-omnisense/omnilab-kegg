# omnilab/kegg

**Not tested live**: KEGG's API is for academic use by academic users only (see
[Verified, and not](#verified-and-not)).

## Installation

```sh
composer require omnilab/kegg
```

```php
use Omnilab\Kegg\KeggSourceFactory;

$kegg = (new KeggSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'databases' => ['compound'],       // optional: what search() searches (compound, drug, reaction, pathway, enzyme)
    'throttle' => 0.34,                // optional: KEGG blocks a caller above three calls a second
]);
```

## The schemes

| What | Scheme | Read from |
|---|---|---|
| a compound, a drug | `kegg` (`C07481`, `D00528`) | the id, `cpd:`/`dr:`, `kegg.compound:`, KEGG's pages |
| a reaction | `kegg.reaction` (`R00200`) | the id, `rn:`, KEGG's pages |
| a pathway map | `kegg.pathway` (`map00010`, an organism's `hsa00010`, `ko00010`, `ec00010`, `rn00010`) | the id, `path:`, KEGG's pages |
| an enzyme | `ec` (`2.7.1.40`, `2.7.1.-`) | the number, `EC 2.7.1.40`, `ec:`, KEGG's, ExPASy's and ExplorEnz's pages |
| a compound by ChEBI | `chebi` (`CHEBI:27732`) | given with its prefix (a bare number is no KEGG id): KEGG's `conv` gives the compound, then `get` |

Anything else is refused before any call (`NotSupportedException`). Glycans (`G00001`), modules,
orthologs, genes, diseases and networks are KEGG entries this package does not read.

## The calls

| Method | Calls |
|---|---|
| `entity()` | `get/{id}` (`get/ec:2.7.1.40` for an enzyme); before it, from a ChEBI id, `conv/compound/chebi:{n}` |
| `search()` | `find/{database}/{text}` for each configured database (a domain keeps its databases: chemistry compound, drug, reaction; biology pathway, enzyme), the lines joined and cut into pages by offset (find does not page) |

KEGG answers a flat file (`ENTRY`, `NAME`, `FORMULA`... in twelve columns, continuation lines
indented), read field by field. An unknown entry is a 404 (`null`); an unknown ChEBI id or a text
nothing matches is an empty answer (`null`, an empty page); a malformed request a 400
(`ProviderException`); 429 or a block a `RateLimitedException` / `ProviderException` as KEGG
answers it.

## What an entity holds

Every entity: `label` (its first name), `synonyms` (the others), `kind` (`compound`, `drug`,
`reaction`, `pathway`, `enzyme`), `url` (its KEGG page; ExPASy's for an enzyme), `domain` (chemistry
for compounds, drugs and reactions; biology for pathways and enzymes).

| Kind | Properties | `identifiers` | `related` |
|---|---|---|---|
| compound, drug | `molecular_formula`, `molecular_weight` (g/mol), `monoisotopic_mass` (Da, KEGG's exact mass), `pubchem_sid` (a PubChem **substance**: KEGG links substances, not compounds - no `pubchem.cid`), `pdb_ccd`, `xref` (KNApSAcK, J-GLOBAL, LIPID MAPS... as `DB: id`); a drug's `efficacy`, `drug_class`, `target` (one each), `atc` (one each), its comment as `description` | `cas`, `chebi`, `chembl`, the other KEGG entry it is the same as (`Same as: D00528`) | `reaction`, `pathway` (the reference maps), `enzyme` (EC), a drug's source `organism` (`ncbi.taxon`) |
| reaction | `definition` (the names' equation), `equation` (KEGG's compounds), `comment` | `rhea` (KEGG's link to Rhea: the same reaction) | `compound`, `enzyme`, `pathway` (`rn` maps) |
| pathway | `pathway_class`, `organism`, `module` (one each), `genes` (how many, an organism's map); its description | none | `compound`, `drug`, `pathway` (related maps), `reference` (the KO map), `go` (KEGG's GO links) |
| enzyme | `enzyme_class`, `systematic_name`, `reaction` (IUBMB's equation, one each), `history`, `organisms` (how many have a gene for it); its comment | `cas` | `reaction` (all its KEGG reactions), `substrate`, `product` (compounds), `pathway` (`ec` maps) |

`image`: a compound's or a drug's structure (`www.kegg.jp/Fig/compound/C07481.gif`), a pathway's
map (`www.kegg.jp/kegg/pathway/map/map00010.png`); none for a reaction or an enzyme.

## The Collector

A KEGG reaction carries its Rhea id (`DBLINKS RHEA: 18160`), and a Rhea reaction its KEGG id: the
`Collector` walks either way between omnilab/kegg and omnilab/rhea. A compound carries its ChEBI id
and CAS number, and ChEBI gives KEGG ids back: PubChem or ChEBI -> KEGG by ChEBI. Not walked live
(see below).

## Verified, and not

**Not tested live.** KEGG's API page says: "KEGG API at rest.kegg.jp is made available only for
academic use by academic users. Non-academic users are requested to follow the guideline shown in
Non-academic use of KEGG." KEGG's legal page: "Non-academic use of KEGG requires a commercial
license" (Pathway Solutions), and "Academic users who utilize KEGG for providing services are
requested to obtain an academic service provider license." Glitchr is not an academic user and
holds no licence, and none was accepted on its behalf. So:

- checked against the service, on 2026-10-16, only while writing, a handful of calls to learn the
  format: `get` of C07481, D00528, R00200, map00010, hsa00010 and `ec:2.7.1.40`; `find` of compound,
  reaction, pathway and enzyme; `conv` from ChEBI and from a PubChem SID; `info/kegg`; a 404
  (`get/C99999`), a 400 (`get/X12345`), an empty `find` and `conv`. The fixtures in `Tests/Fixtures/`
  are excerpts of those answers (references, BRITE trees and gene lists cut short; `find/drug`'s
  line built from D00528's names);
- not verified: the package itself against the service, the throttle against KEGG's block, the
  answers to a 429, the `Collector` walks above, `search()` across several databases. No section of
  the workspace's `.samples/live.php` calls KEGG.

Run it live only if your use is academic, or under KEGG's licence.
