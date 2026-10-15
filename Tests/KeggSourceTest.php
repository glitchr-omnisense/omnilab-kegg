<?php

namespace Omnilab\Kegg\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Kegg\KeggSource;
use Omnilab\Kegg\KeggSourceFactory;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures: excerpts of rest.kegg.jp's answers seen on 2026-10-15 while
 * the format was checked (caffeine C07481 and D00528, pyruvate kinase's
 * reaction R00200 and enzyme 2.7.1.40, glycolysis map00010; the
 * references, BRITE trees and gene lists cut short), conv's line for
 * ChEBI 27732, find's lines for caffeine. No live check is kept: KEGG's
 * API is for academic users (README).
 */
final class KeggSourceTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): KeggSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            self::assertStringStartsWith(KeggSource::BASE_URI, $url);
            $this->calls[] = $call = rawurldecode(substr($url, \strlen(KeggSource::BASE_URI)));
            if ($response) {
                return $response;
            }
            $fixture = match ($call) {
                'get/C07481' => 'get-C07481.txt',
                'get/D00528' => 'get-D00528.txt',
                'get/R00200' => 'get-R00200.txt',
                'get/map00010' => 'get-map00010.txt',
                'get/ec:2.7.1.40' => 'get-ec-2.7.1.40.txt',
                'conv/compound/chebi:27732' => 'conv-chebi-27732.txt',
                'find/compound/caffeine' => 'find-compound-caffeine.txt',
                'find/drug/caffeine' => 'find-drug-caffeine.txt',
                default => null,
            };
            if (null === $fixture) {
                // KEGG: an unknown entry is a 404 with no body, an unknown conv or find a 200 with an empty line.
                return new MockResponse(str_starts_with($call, 'get/') ? '' : "\n", ['http_code' => str_starts_with($call, 'get/') ? 404 : 200]);
            }

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });
        $source = (new KeggSourceFactory($http))->create(['throttle' => 0] + $options);
        self::assertInstanceOf(KeggSource::class, $source);

        return $source;
    }

    public function testCaffeineAsACompound(): void
    {
        $caffeine = $this->source()->entity('C07481');

        self::assertSame(['get/C07481'], $this->calls);
        self::assertSame('kegg:C07481', $caffeine->id->key());
        self::assertSame('Caffeine', $caffeine->label);
        self::assertSame(['1,3,7-Trimethylxanthine'], $caffeine->synonyms);
        self::assertSame(Domain::CHEMISTRY, $caffeine->domain);
        self::assertSame('compound', $caffeine->kind);
        self::assertSame('https://www.kegg.jp/entry/C07481', $caffeine->url);
        self::assertSame('https://www.kegg.jp/Fig/compound/C07481.gif', $caffeine->image);
        self::assertSame('C8H10N4O2', $caffeine->value('molecular_formula'));
        self::assertEquals(new Quantity(194.19, 'g/mol'), $caffeine->value('molecular_weight'));
        self::assertEquals(new Quantity(194.0804, 'Da'), $caffeine->value('monoisotopic_mass'));
        self::assertSame('9684', $caffeine->value('pubchem_sid'), 'a substance, not the compound\'s CID');
        self::assertSame('CFF', $caffeine->value('pdb_ccd'));
        self::assertSame(['KNApSAcK: C00001492', 'J-GLOBAL: J2.330B'], array_map('strval', $caffeine->properties('xref')));
        self::assertSame(['cas:58-08-2', 'chebi:27732', 'kegg:D00528'], $caffeine->identifiers->keys());
        self::assertNull($caffeine->identifier(Scheme::PUBCHEM_CID));
        self::assertSame(['R07920', 'R07921', 'R07939', 'R07954', 'R07955', 'R07956', 'R07971', 'R07972', 'R07980'], $caffeine->related('reaction')->values(Scheme::KEGG_REACTION));
        self::assertSame(['map00232', 'map01060', 'map01065', 'map01100', 'map01110', 'map01120'], $caffeine->related('pathway')->values(Scheme::KEGG_PATHWAY));
        self::assertSame(['1.13.12.-', '1.14.13.178', '1.14.13.179', '1.14.14.1', '1.17.5.2', '2.1.1.160'], $caffeine->related('enzyme')->values(Scheme::EC));
    }

    public function testCaffeineAsADrug(): void
    {
        $drug = $this->source()->entity(Identifier::of(Scheme::KEGG, 'dr:D00528'));

        self::assertSame('kegg:D00528', $drug->id->key());
        self::assertSame('drug', $drug->kind);
        self::assertSame('Caffeine (USP)', $drug->label);
        self::assertSame(['Anhydrous caffeine (JP19)', 'Anhydrous caffeine (TN)', 'Respia (TN)'], $drug->synonyms);
        self::assertSame('Stimulant (central), Adenosine receptor antagonist, Phosphodiesterase inhibitor', $drug->value('efficacy'));
        self::assertSame('Respiratory system agent', $drug->value('drug_class'));
        self::assertSame(['D11AX26', 'N06BC01'], array_map('strval', $drug->properties('atc')));
        self::assertSame(['ADORA1', 'ADORA2A', 'PDE'], array_map('strval', $drug->properties('target')));
        self::assertSame('Xanthine derivative', $drug->description);
        self::assertSame(['cas:58-08-2', 'chebi:27732', 'kegg:C07481'], $drug->identifiers->keys());
        self::assertSame(['13443'], $drug->related('organism')->values(Scheme::NCBI_TAXON), 'Coffea arabica, its source');
    }

    public function testAReactionWithItsRheaIdAndCompounds(): void
    {
        $reaction = $this->source()->entity('rn:R00200');

        self::assertSame(['get/R00200'], $this->calls);
        self::assertSame('kegg.reaction:R00200', $reaction->id->key());
        self::assertSame('ATP:pyruvate 2-O-phosphotransferase', $reaction->label);
        self::assertSame('reaction', $reaction->kind);
        self::assertSame('ATP + Pyruvate <=> ADP + Phosphoenolpyruvate', $reaction->value('definition'));
        self::assertSame('C00002 + C00022 <=> C00008 + C00074', $reaction->value('equation'));
        self::assertSame(['rhea:18160'], $reaction->identifiers->keys(), 'the same reaction in Rhea: the Collector follows it');
        self::assertSame(['C00002', 'C00022', 'C00008', 'C00074'], $reaction->related('compound')->values(Scheme::KEGG));
        self::assertSame(['2.7.1.40'], $reaction->related('enzyme')->values(Scheme::EC));
        self::assertSame(['rn00010', 'rn00620', 'rn01100', 'rn01110', 'rn01120', 'rn01200', 'rn01230'], $reaction->related('pathway')->values(Scheme::KEGG_PATHWAY));
        self::assertNull($reaction->image);
    }

    public function testAPathwayMap(): void
    {
        $pathway = $this->source()->entity('https://www.kegg.jp/pathway/map00010');

        self::assertSame(['get/map00010'], $this->calls);
        self::assertSame('kegg.pathway:map00010', $pathway->id->key());
        self::assertSame('Glycolysis / Gluconeogenesis', $pathway->label);
        self::assertSame(Domain::BIOLOGY, $pathway->domain);
        self::assertSame('pathway', $pathway->kind);
        self::assertStringStartsWith('Glycolysis is the process of converting glucose into pyruvate', (string) $pathway->description);
        self::assertSame('Metabolism; Carbohydrate metabolism', $pathway->value('pathway_class'));
        self::assertSame('M00001 Glycolysis (Embden-Meyerhof pathway), glucose => pyruvate', $pathway->value('module'));
        self::assertSame(['0006096', '0006094'], $pathway->related('go')->values(Scheme::GO));
        self::assertSame(['map00020', 'map00030', 'map00500', 'map00620', 'map00640', 'map00710'], $pathway->related('pathway')->values(Scheme::KEGG_PATHWAY));
        self::assertSame(['ko00010'], $pathway->related('reference')->values(Scheme::KEGG_PATHWAY));
        self::assertSame('https://www.kegg.jp/kegg/pathway/map/map00010.png', $pathway->image);
    }

    public function testAnEnzymeByItsEcNumber(): void
    {
        $enzyme = $this->source()->entity('EC 2.7.1.40');

        self::assertSame(['get/ec:2.7.1.40'], $this->calls);
        self::assertSame('ec:2.7.1.40', $enzyme->id->key());
        self::assertSame('pyruvate kinase', $enzyme->label);
        self::assertSame(['phosphoenolpyruvate kinase', 'phosphoenol transphosphorylase'], $enzyme->synonyms);
        self::assertSame('enzyme', $enzyme->kind);
        self::assertSame(Domain::BIOLOGY, $enzyme->domain);
        self::assertSame('Transferases; Transferring phosphorus-containing groups; Phosphotransferases with an alcohol group as acceptor', $enzyme->value('enzyme_class'));
        self::assertSame('ATP:pyruvate 2-O-phosphotransferase', $enzyme->value('systematic_name'));
        self::assertSame('ATP + pyruvate = ADP + phosphoenolpyruvate', $enzyme->value('reaction'));
        self::assertSame('EC 2.7.1.40 created 1961', $enzyme->value('history'));
        self::assertSame(4, $enzyme->value('organisms'));
        self::assertStringStartsWith('UTP, GTP, CTP, ITP and dATP can also act as donors.', (string) $enzyme->description);
        self::assertSame(['cas:9001-59-6'], $enzyme->identifiers->keys());
        self::assertSame(['R00200', 'R00430', 'R00572', 'R00659', 'R00724', 'R01138', 'R01858', 'R02320'], $enzyme->related('reaction')->values(Scheme::KEGG_REACTION));
        self::assertSame(['C00002', 'C00022'], $enzyme->related('substrate')->values(Scheme::KEGG));
        self::assertSame(['C00008', 'C00074'], $enzyme->related('product')->values(Scheme::KEGG));
        self::assertSame('ec00010', $enzyme->related('pathway')->value(Scheme::KEGG_PATHWAY));
        self::assertSame('https://enzyme.expasy.org/EC/2.7.1.40', $enzyme->url);
    }

    public function testACompoundByItsChebiIdThroughConv(): void
    {
        $caffeine = $this->source()->entity('CHEBI:27732');

        self::assertSame(['conv/compound/chebi:27732', 'get/C07481'], $this->calls);
        self::assertSame('kegg:C07481', $caffeine->id->key());
        self::assertNull($this->source()->entity(Identifier::chebi('1')), 'no KEGG compound: conv answers nothing');
    }

    public function testUnknownIsNullAndOtherBasesAreRefusedBeforeAnyCall(): void
    {
        self::assertNull($this->source()->entity('C99999'));
        self::assertSame(['get/C99999'], $this->calls);
        $this->calls = [];
        foreach (['caffeine', 'pubchem.cid:2519', '27732', 'Q60235'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail($id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testSearchTheConfiguredDatabasesInPages(): void
    {
        $source = $this->source(options: ['databases' => ['compound', 'drug']]);
        $page = $source->search(new Query('caffeine', limit: 2));

        self::assertSame(['find/compound/caffeine', 'find/drug/caffeine'], $this->calls);
        self::assertSame(3, $page->total);
        self::assertSame(['kegg:C07481', 'kegg:C13403'], array_map(static fn ($e) => $e->id->key(), $page->items));
        self::assertSame(['Caffeine', '1,3,7-Trimethylxanthine'], [$page->first()->label, ...$page->first()->synonyms]);
        $next = $source->search(new Query('caffeine', limit: 2, cursor: $page->next));
        self::assertSame(['kegg:D00528'], array_map(static fn ($e) => $e->id->key(), $next->items));
        self::assertSame('drug', $next->first()->kind);
        self::assertTrue($next->isLast());

        self::assertCount(0, $source->search(new Query('caffeine', Domain::BIOLOGY)), 'neither compound nor drug is of biology');
        self::assertCount(0, $this->source()->search(new Query('zzqqxx')), 'find answers an empty line');
    }

    public function testErrorsAndOptions(): void
    {
        try {
            $this->source(new MockResponse('', ['http_code' => 400]))->entity('C07481');
            self::fail('400');
        } catch (ProviderException $e) {
            self::assertStringContainsString('400', $e->getMessage());
        }
        $this->expectException(RateLimitedException::class);
        try {
            new KeggSource(new MockHttpClient(), ['glycan']);
            self::fail('glycan');
        } catch (\InvalidArgumentException) {
        }
        $this->source(new MockResponse('', ['http_code' => 429]))->entity('C07481');
    }

    public function testFactoryAndCapabilities(): void
    {
        $factory = new KeggSourceFactory();
        self::assertSame('kegg', $factory->getName());
        $source = $factory->create();
        self::assertSame('kegg', $source->getName());
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::STRUCTURE, Capability::CROSS_REFERENCES], $source->capabilities());
        self::assertSame(0.34, $factory->createConfig()->get('throttle'));
    }
}
