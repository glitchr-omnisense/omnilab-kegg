# omnilab/kegg

KEGG's REST API (Kanehisa Laboratories: `https://rest.kegg.jp/`) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): compounds, drugs, reactions,
pathway maps and enzymes by KEGG id - formula, masses, equations, classes; CAS, ChEBI, ChEMBL and
Rhea ids as cross-references; reactions, pathways, enzymes and compounds as related things -, as
entities; a search. No key; for academic use by academic users only.

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

## Credentials

No key. But KEGG's API "is made available only for academic use by academic users": a
non-academic user needs a licence from Pathway Solutions, and an academic user who uses KEGG to
provide a service an academic service provider licence. The user is the one who must be an
academic user or hold a licence; the package asks for nothing and checks nothing.

## Options

The factory's options, with the defaults it applies (`KeggSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `databases` | `list<string>` | `[compound]` | no | what `search()` searches, in this order: `compound`, `drug`, `reaction`, `pathway`, `enzyme` (another is an `\InvalidArgumentException` at `create()`) |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0.34` | no | seconds left between two calls: KEGG blocks a caller above three a second |
| `base_uri` | `string` | `https://rest.kegg.jp/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`, `search`, `structure`, `cross_references`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a compound or drug (`C07481`, `D00528`), a reaction (`R00200`), a pathway map (`map00010`, `hsa00010`), an enzyme (`2.7.1.40`), or a ChEBI id with its prefix (KEGG's `conv`, then `get`); another scheme refused before any call |
| `search` | yes | `find` in each configured database (a domain keeps its databases), the lines joined and paged by offset here (`find` does not page) |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | yes | a compound's or a drug's image is its structure (KEGG's GIF); a pathway's is its map |
| `cross_references` | yes | CAS, ChEBI and ChEMBL ids, the KEGG entry it is the same as, a reaction's Rhea id |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

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

## Example

Not run against KEGG (its API is for academic use by academic users, and the authors hold no
licence). The script below reads KEGG's answers of 2026-10-15 as the package's tests keep them,
through a `MockHttpClient`; with `HttpClient::create()` in place of `$http`, it calls KEGG.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Kegg\KeggSourceFactory;
use Omnilab\Model\Scheme;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// KEGG's answers of 2026-10-15 as the package's tests keep them (no call to KEGG: its API is for academic users only).
$fixtures = __DIR__.'/vendor/omnilab/kegg/Tests/Fixtures/';
$http = new MockHttpClient(static function (string $method, string $url) use ($fixtures): MockResponse {
    $file = str_replace(['/', ':'], ['-', '-'], ltrim((string) parse_url($url, PHP_URL_PATH), '/')).'.txt';

    return is_file($fixtures.$file) ? new MockResponse((string) file_get_contents($fixtures.$file)) : new MockResponse('', ['http_code' => 404]);
});
$kegg = (new KeggSourceFactory($http))->create(['throttle' => 0, 'databases' => ['compound', 'drug']]);

$caffeine = $kegg->entity('C07481');
echo $caffeine->id, ' ', $caffeine->label, ': ', $caffeine->value('molecular_formula'), ', ', $caffeine->property('molecular_weight'), ', CAS ', $caffeine->identifier(Scheme::CAS), ', ChEBI ', $caffeine->identifier(Scheme::CHEBI), "\n";
echo 'R00200: ', $kegg->entity('rn:R00200')?->value('definition'), ', Rhea ', $kegg->entity('rn:R00200')?->identifier(Scheme::RHEA), "\n";
foreach ($kegg->search(new Query('caffeine', limit: 3)) as $found) {
    echo '  ', $found->id, '  ', $found->label, "\n";
}
```

```
kegg:C07481 Caffeine: C8H10N4O2, 194.19 g/mol, CAS 58-08-2, ChEBI 27732
R00200: ATP + Pyruvate <=> ADP + Phosphoenolpyruvate, Rhea 18160
  kegg:C07481  Caffeine
  kegg:C13403  Sodium caffeine benzoate
  kegg:D00528  Caffeine (USP)
```

(run on 2026-10-27 in the harness image, with no network, on those fixtures)

```yaml
# config/packages/omnilab.yaml - for academic use by academic users, or under KEGG's licence
omnilab:
    sources:
        kegg: { factory: kegg, options: { mailto: '%env(OMNILAB_MAILTO)%', databases: [compound, drug] } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

KEGG's own: an unknown entry is a 404 (null); an unknown ChEBI id or a text nothing matches an
empty answer (null, an empty page); a malformed request a 400 (`ProviderException`); past three
calls a second KEGG blocks the caller - a 429 is a `RateLimitedException`, another refusal a
`ProviderException` as KEGG answers it. Before any call: an identifier of a scheme this package
does not read (`NotSupportedException`).

## Verified, and not

**Not tested live.** KEGG's API page says: "KEGG API at rest.kegg.jp is made available only for
academic use by academic users. Non-academic users are requested to follow the guideline shown in
Non-academic use of KEGG." KEGG's legal page: "Non-academic use of KEGG requires a commercial
license" (Pathway Solutions), and "Academic users who utilize KEGG for providing services are
requested to obtain an academic service provider license." The authors hold no KEGG licence and
make no commercial use of this package; whoever runs it is the one who must be an academic user or
hold a licence. So:

- checked against the service, on 2026-10-15, only while writing, a handful of calls to learn the
  format: `get` of C07481, D00528, R00200, map00010, hsa00010 and `ec:2.7.1.40`; `find` of compound,
  reaction, pathway and enzyme; `conv` from ChEBI and from a PubChem SID; `info/kegg`; a 404
  (`get/C99999`), a 400 (`get/X12345`), an empty `find` and `conv`. The fixtures in `Tests/Fixtures/`
  are excerpts of those answers (references, BRITE trees and gene lists cut short; `find/drug`'s
  line built from D00528's names);
- not verified: the package itself against the service, the throttle against KEGG's block, the
  answers to a 429, the `Collector` walks above, `search()` across several databases. No section of
  the workspace's `.samples/live.php` calls KEGG.

On 2026-10-27 the example above ran on the fixtures only, with no network.

Run it live only if your use is academic, or under KEGG's licence.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Kanehisa Laboratories or Pathway Solutions. KEGG is "an original database product,
copyright Kanehisa Laboratories", "not a public database"; its API is for academic use by academic
users only, other uses need a licence, and KEGG is to be cited, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-kegg#terms-of-use) and
[kegg.jp/kegg/legal.html](https://www.kegg.jp/kegg/legal.html) say. Whoever runs the package is
the one who must be an academic user or hold a licence, and is responsible for any use made of what
it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-kegg#responsibility) section.
