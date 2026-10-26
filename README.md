# omnilab/kegg

> **Not tested live.** KEGG's API at rest.kegg.jp is "made available only for academic use by
> academic users"; non-academic use of KEGG needs a commercial licence from Pathway Solutions.
> This package was not exercised against the service: the answers' format was checked on a
> handful of calls on 2026-10-15 while it was written (the test fixtures are trimmed excerpts of
> them), and no live check is kept. Whoever runs it must be an academic user or hold a licence:
> that is theirs to obtain (see [Responsibility](#responsibility)). Details in
> [docs/index.md](docs/index.md#verified-and-not).

KEGG, the Kyoto Encyclopedia of Genes and Genomes, for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): a compound (`C07481`, caffeine), a
drug (`D00528`), a reaction (`R00200`), a pathway map (`map00010`, `hsa00010`) or an enzyme
(`2.7.1.40`) by its KEGG identifier - names, formula and masses, a reaction's equation, a pathway's
class and description, an enzyme's class and systematic name; the same thing in CAS, ChEBI, ChEMBL
and Rhea as identifiers; the reactions, pathways, enzymes, compounds and GO terms it is tied to as
related things -; a compound by its ChEBI id; a search. No key.

```php
$kegg = (new KeggSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$caffeine = $kegg->entity('C07481');            // or 'cpd:C07481', 'CHEBI:27732' (KEGG's conv), its page
$caffeine->value('molecular_weight');           // 194.19 g/mol
$caffeine->identifiers->keys();                 // cas:58-08-2, chebi:27732, kegg:D00528
$caffeine->related('pathway')->keys();          // kegg.pathway:map00232 (Caffeine metabolism), ...
$caffeine->value('pubchem_sid');                // 9684: KEGG links PubChem substances, not compounds
$reaction = $kegg->entity('R00200');            // ATP:pyruvate 2-O-phosphotransferase
$reaction->identifiers->keys();                 // rhea:18160: the Collector follows it into omnilab/rhea
$reaction->related('compound')->keys();         // kegg:C00002, kegg:C00022, kegg:C00008, kegg:C00074
$kegg->entity('EC 2.7.1.40')->label;            // pyruvate kinase
$kegg->entity('map00010')->related('go')->keys();   // go:0006096, go:0006094
$kegg->search(new Query('caffeine'));           // C07481 Caffeine, C13403 Sodium caffeine benzoate
```

```yaml
omnilab:
    sources:
        kegg:
            factory: kegg
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                databases: ['compound']        # what search() searches: compound, drug, reaction, pathway, enzyme
                throttle: 0.34                 # KEGG blocks a caller above three calls a second
```

## Terms of use

KEGG is "an original database product, copyright Kanehisa Laboratories", and "not a public
database" ([kegg.jp/kegg/legal.html](https://www.kegg.jp/kegg/legal.html)). Its API "is made
available only for academic use by academic users"; non-academic users follow
KEGG's "Non-academic use of KEGG" and need a licence from Pathway Solutions, which operates
KEGG's FTP sites. Academic users who use KEGG to provide a service need
an academic service provider licence. At most three calls a second, or the caller is blocked. Cite KEGG: Kanehisa, M. and Goto, S., KEGG: Kyoto Encyclopedia of
Genes and Genomes, Nucleic Acids Res. 28, 27-30 (2000), and the latest paper kegg.jp lists.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Kanehisa Laboratories and Pathway Solutions (KEGG). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License (of this package's code): MIT.
