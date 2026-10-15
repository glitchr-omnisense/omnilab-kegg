<?php

namespace Omnilab\Kegg;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * KEGG, the Kyoto Encyclopedia of Genes and Genomes (Kanehisa
 * Laboratories), through its REST API at rest.kegg.jp: a compound
 * ("C07481", caffeine), a drug ("D00528"), a reaction ("R00200"), a
 * pathway map ("map00010", "hsa00010") or an enzyme ("2.7.1.40") by its
 * KEGG identifier - names, formula, masses, a reaction's equation, a
 * pathway's class and description, an enzyme's class and systematic name;
 * the same thing in other bases (CAS, ChEBI, ChEMBL, Rhea) as
 * identifiers; the reactions, pathways, enzymes and compounds it is tied
 * to as related things -; a compound by its ChEBI id (KEGG's conv); a
 * search of the configured databases (find).
 *
 * KEGG's API is for academic use by academic users only (non-academic
 * use needs a licence from Pathway Solutions); three calls a second at
 * most, or KEGG blocks the caller: 0.34 s between two calls by default.
 * One call an entity (two from a ChEBI id), one a database searched.
 */
final class KeggSource extends HttpSource
{
    public const BASE_URI = 'https://rest.kegg.jp/';

    /** The databases find can search, and the domain each belongs to. */
    public const DATABASES = ['compound' => Domain::CHEMISTRY, 'drug' => Domain::CHEMISTRY, 'reaction' => Domain::CHEMISTRY, 'pathway' => Domain::BIOLOGY, 'enzyme' => Domain::BIOLOGY];

    /** KEGG's DBLINKS that name the same thing in a base the core knows. */
    private const SAME = ['CAS' => Scheme::CAS, 'ChEBI' => Scheme::CHEBI, 'ChEMBL' => Scheme::CHEMBL, 'RHEA' => Scheme::RHEA];

    /**
     * @param list<string> $databases what search() searches, in this order (compound, drug, reaction, pathway, enzyme)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly array $databases = ['compound'],
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.34,
    ) {
        foreach ($databases as $database) {
            if (!isset(self::DATABASES[$database])) {
                throw new \InvalidArgumentException(\sprintf('KEGG cannot search "%s" here (it searches %s).', $database, implode(', ', array_keys(self::DATABASES))));
            }
        }
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'kegg';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::STRUCTURE, Capability::CROSS_REFERENCES];
    }

    /**
     * A compound or drug ("C07481", "cpd:C07481", "D00528"), a reaction
     * ("R00200", "rn:R00200"), a pathway ("map00010", "hsa00010",
     * "path:map00010"), an enzyme ("2.7.1.40", "EC 2.7.1.40", "ec:2.7.1.40"),
     * a KEGG page, or a compound by its ChEBI id ("CHEBI:27732": KEGG's conv
     * first); another base's identifier is refused before any call; null
     * when KEGG has no such entry.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $identifier = self::identify($id, Scheme::KEGG, Scheme::KEGG_REACTION, Scheme::KEGG_PATHWAY, Scheme::EC)
            ?? self::chebi($id)
            ?? throw NotSupportedException::identifier('kegg', (string) $id);
        if (Scheme::CHEBI === $identifier->scheme) {
            // "chebi:27732<TAB>cpd:C07481", one line a compound; nothing when KEGG has none.
            $line = trim((string) $this->get('conv/compound/chebi:'.$identifier->value));
            if (!preg_match('~\tcpd:(C\d{5})~', $line, $m)) {
                return null;
            }
            $identifier = Identifier::of(Scheme::KEGG, $m[1]);
        }
        $text = $this->get('get/'.(Scheme::EC === $identifier->scheme ? 'ec:' : '').$identifier->value);
        if (null === $text || '' === trim($text)) {
            return null;
        }

        return $this->toEntity(self::record($text), $identifier);
    }

    /**
     * The entries whose names hold the text, in the configured databases
     * (compound by default), in KEGG's order, one database after the other;
     * a domain narrows to its databases (chemistry: compound, drug,
     * reaction; biology: pathway, enzyme). An entity of a page holds its id
     * and names: entity() reads it whole.
     */
    public function search(Query $query): Page
    {
        $text = trim((string) preg_replace('~\s+~u', ' ', $query->text));
        $databases = array_values(array_filter($this->databases, static fn (string $db) => null === $query->domain || self::DATABASES[$db] === $query->domain));
        if ('' === $text || !$databases) {
            return new Page();
        }
        $items = [];
        foreach ($databases as $database) {
            // KEGG reads a slash as a path's: the text is one segment, escaped.
            foreach (preg_split('~\R~', (string) $this->get('find/'.$database.'/'.rawurlencode($text))) ?: [] as $line) {
                if (null !== ($entity = $this->fromFind($database, $line))) {
                    $items[] = $entity;
                }
            }
        }
        $start = max(0, (int) $query->cursor);
        $limit = max(1, $query->limit);
        $total = \count($items);

        return new Page(\array_slice($items, $start, $limit), $start + $limit < $total ? (string) ($start + $limit) : null, $total);
    }

    /** A ChEBI id given with its prefix or as an Identifier: KEGG's conv reads it. */
    private static function chebi(Identifier|string $id): ?Identifier
    {
        $identifier = $id instanceof Identifier ? $id : Identifier::parse($id);

        return Scheme::CHEBI === $identifier?->scheme ? $identifier : null;
    }

    /**
     * A KEGG flat file read into its fields: each a list of lines, the
     * continuation lines (twelve spaces) added to the field above them, a
     * sub-field ("  PATHWAY" under TARGET) as "TARGET.PATHWAY".
     *
     * @return array<string, list<string>>
     */
    private static function record(string $text): array
    {
        $fields = [];
        $key = null;
        foreach (preg_split('~\R~', $text) ?: [] as $line) {
            if (str_starts_with($line, '///')) {
                break;
            }
            if ('' === trim($line)) {
                continue;
            }
            if (preg_match('~^([A-Z][A-Z_]*)(?:\s+(.*))?$~', $line, $m)) {
                $key = $m[1];
                $parent = $key;
            } elseif (null !== $key && preg_match('~^ {1,3}([A-Z][A-Z_]+)(?:\s+(.*))?$~', $line, $m)) {
                $key = ($parent ?? $key).'.'.$m[1];
            } elseif (null !== $key) {
                $m = [1 => $key, 2 => trim($line)];
            } else {
                continue;
            }
            $fields[$key][] = rtrim($m[2] ?? '');
        }

        return $fields;
    }

    /** @param array<string, list<string>> $f */
    private function toEntity(array $f, Identifier $asked): ?Entity
    {
        // "C07481                      Compound", "EC 2.7.1.40                 Enzyme", "hsa00010                    Pathway".
        if (!preg_match('~^(?:EC\s+)?(\S+)\s+(?:\S+\s+)*?([A-Z][a-z]+)$~', trim($f['ENTRY'][0] ?? ''), $m)) {
            return null;
        }
        [, $entry, $type] = $m;
        $id = match ($type) {
            'Compound', 'Drug' => Identifier::tryOf(Scheme::KEGG, $entry),
            'Reaction' => Identifier::tryOf(Scheme::KEGG_REACTION, $entry),
            'Pathway' => Identifier::tryOf(Scheme::KEGG_PATHWAY, $entry),
            'Enzyme' => Identifier::tryOf(Scheme::EC, $entry),
            default => null,
        } ?? $asked;

        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'kegg');
            }
        };
        $names = array_values(array_filter(array_map(static fn (string $n) => self::plain(rtrim($n, ';')), $f['NAME'] ?? [])));
        $identifiers = [];
        $related = [];
        $relate = static function (string $relation, ?Identifier ...$ids) use (&$related): void {
            $related[$relation] = ($related[$relation] ?? new Identifiers())->with(...$ids);
        };
        $ids = static fn (string $pattern, array $lines): array => preg_match_all($pattern, implode(' ', $lines), $all) ? $all[1] : [];

        // DBLINKS: "CAS: 58-08-2", "ChEBI: 27732 12345", "PubChem: 9684" (a substance, SID: not a compound's CID).
        foreach ($f['DBLINKS'] ?? [] as $link) {
            if (!preg_match('~^(.+?):\s+(.+)$~', $link, $l)) {
                continue;
            }
            $values = preg_split('~\s+~', trim($l[2])) ?: [];
            if (isset(self::SAME[$l[1]])) {
                foreach ($values as $value) {
                    $identifiers[] = Identifier::tryOf(self::SAME[$l[1]], $value);
                }
            } elseif ('GO' === $l[1]) {
                $relate('go', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::GO, $v), $values));
            } elseif ('PubChem' === $l[1]) {
                $add('pubchem_sid', 'PubChem substance (SID)', $values[0] ?? null);
            } elseif ('PDB-CCD' === $l[1]) {
                $add('pdb_ccd', 'PDB chemical component', $values[0] ?? null);
            } elseif (!\in_array($l[1], ['ExplorEnz - The Enzyme Database', 'IUBMB Enzyme Nomenclature', 'ExPASy - ENZYME nomenclature database', 'BRENDA, the Enzyme Database'], true)) {
                $add('xref', 'Other database', $l[1].': '.trim($l[2]));
            }
        }
        // "Same as: D00528" (a compound's drug, a drug's compound, a glycan's compound).
        foreach ($f['REMARK'] ?? [] as $remark) {
            if (preg_match('~^Same as:\s+(.+)$~', $remark, $r)) {
                foreach (preg_split('~\s+~', $r[1]) ?: [] as $same) {
                    $identifiers[] = Identifier::tryOf(Scheme::KEGG, $same);
                }
            } elseif (preg_match('~^ATC code:\s+(.+)$~', $remark, $r)) {
                foreach (preg_split('~\s+~', trim($r[1])) ?: [] as $atc) {
                    $add('atc', 'ATC code', $atc);
                }
            }
        }

        $domain = Domain::CHEMISTRY;
        $description = null;
        $image = null;
        switch ($type) {
            case 'Compound':
            case 'Drug':
                $add('molecular_formula', 'Molecular formula', self::plain($f['FORMULA'][0] ?? null));
                $add('monoisotopic_mass', 'Monoisotopic mass', is_numeric($f['EXACT_MASS'][0] ?? null) ? new Quantity((float) $f['EXACT_MASS'][0], 'Da') : null);
                $add('molecular_weight', 'Molecular weight', is_numeric($f['MOL_WEIGHT'][0] ?? null) ? new Quantity((float) $f['MOL_WEIGHT'][0], 'g/mol') : null);
                $add('efficacy', 'Efficacy', self::plain($f['EFFICACY'][0] ?? null));
                $add('drug_class', 'Drug class', self::plain($f['CLASS'][0] ?? null));
                foreach ($f['TARGET'] ?? [] as $target) {
                    $add('target', 'Target', self::plain((string) preg_replace('~\s*\[.*$~', '', $target)));
                }
                $description = self::plain(implode(' ', $f['COMMENT'] ?? []));
                $relate('reaction', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::KEGG_REACTION, $v), $ids('~\b(R\d{5})\b~', $f['REACTION'] ?? [])));
                $relate('pathway', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::KEGG_PATHWAY, $v), $ids('~^(map\d{5})\b~m', [implode("\n", $f['PATHWAY'] ?? [])])));
                $relate('enzyme', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::EC, $v), $ids('~(\d+\.[\d.n-]+)~', $f['ENZYME'] ?? [])));
                // A drug's source organism: "Coffea arabica [TAX:13443]".
                $relate('organism', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::NCBI_TAXON, $v), $ids('~\[TAX:(\d+)\]~', $f['SOURCE'] ?? [])));
                $image = 'https://www.kegg.jp/Fig/'.strtolower($type).'/'.$id->value.'.gif';
                break;
            case 'Reaction':
                $add('definition', 'Definition', self::plain($f['DEFINITION'][0] ?? null));
                $add('equation', 'Equation (KEGG compounds)', self::plain($f['EQUATION'][0] ?? null));
                $add('comment', 'Comment', self::plain(implode(' ', $f['COMMENT'] ?? [])));
                $relate('compound', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::KEGG, $v), $ids('~\b([CD]\d{5})\b~', $f['EQUATION'] ?? [])));
                $relate('enzyme', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::EC, $v), $ids('~(\d+\.[\d.n-]+)~', $f['ENZYME'] ?? [])));
                $relate('pathway', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::KEGG_PATHWAY, $v), $ids('~^([a-z]{2,4}\d{5})\b~m', [implode("\n", $f['PATHWAY'] ?? [])])));
                if (!$names && isset($f['DEFINITION'][0])) {
                    $names = [(string) self::plain($f['DEFINITION'][0])];
                }
                break;
            case 'Pathway':
                $domain = Domain::BIOLOGY;
                $description = self::plain(implode(' ', $f['DESCRIPTION'] ?? []));
                $add('pathway_class', 'Class', self::plain($f['CLASS'][0] ?? null));
                $add('organism', 'Organism', self::plain((string) preg_replace('~\s*\[GN:.*$~', '', $f['ORGANISM'][0] ?? '')));
                foreach ($f['MODULE'] ?? [] as $module) {
                    $add('module', 'Module', self::plain((string) preg_replace('~\s*\[PATH:.*$~', '', $module)));
                }
                $add('genes', 'Genes', isset($f['GENE']) ? \count($f['GENE']) : null);
                $relate('compound', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::KEGG, $v), $ids('~^(C\d{5})\b~m', [implode("\n", $f['COMPOUND'] ?? [])])));
                $relate('drug', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::KEGG, $v), $ids('~^(D\d{5})\b~m', [implode("\n", $f['DRUG'] ?? [])])));
                $relate('pathway', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::KEGG_PATHWAY, $v), $ids('~^([a-z]{2,4}\d{5})\b~m', [implode("\n", $f['REL_PATHWAY'] ?? [])])));
                $relate('reference', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::KEGG_PATHWAY, $v), $ids('~^([a-z]{2,4}\d{5})\b~m', [implode("\n", $f['KO_PATHWAY'] ?? [])])));
                // A pathway's name in an organism ends with it: "Glycolysis / Gluconeogenesis - Homo sapiens (human)".
                preg_match('~^([a-z]{2,4})\d{5}$~', $id->value, $p);
                $image = 'https://www.kegg.jp/kegg/pathway/'.($p[1] ?? 'map').'/'.$id->value.'.png';
                break;
            case 'Enzyme':
                $domain = Domain::BIOLOGY;
                $description = self::plain(implode(' ', $f['COMMENT'] ?? []));
                // "Transferases;" then its sub-class and sub-sub-class, one a line.
                $classes = array_values(array_filter(array_map(static fn (string $c) => self::plain(rtrim($c, ';')), $f['CLASS'] ?? [])));
                $add('enzyme_class', 'Class', $classes ? implode('; ', $classes) : null);
                $add('systematic_name', 'Systematic name', self::plain($f['SYSNAME'][0] ?? null));
                foreach ($f['REACTION'] ?? [] as $reaction) {
                    $add('reaction', 'Reaction (IUBMB)', self::plain((string) preg_replace('~\s*\[RN:[^]]*\]~', '', rtrim($reaction, ';'))));
                }
                $add('history', 'History', self::plain($f['HISTORY'][0] ?? null));
                $add('organisms', 'Organisms with a gene for it', isset($f['GENES']) ? \count($f['GENES']) : null);
                $relate('reaction', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::KEGG_REACTION, $v), $ids('~\b(R\d{5})\b~', $f['ALL_REAC'] ?? [])));
                $relate('substrate', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::KEGG, $v), $ids('~\[CPD:(C\d{5})\]~', $f['SUBSTRATE'] ?? [])));
                $relate('product', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::KEGG, $v), $ids('~\[CPD:(C\d{5})\]~', $f['PRODUCT'] ?? [])));
                $relate('pathway', ...array_map(static fn (string $v) => Identifier::tryOf(Scheme::KEGG_PATHWAY, $v), $ids('~^([a-z]{2,4}\d{5})\b~m', [implode("\n", $f['PATHWAY'] ?? [])])));
                break;
            default:
                return null;
        }

        return new Entity(
            id: $id,
            label: $names[0] ?? $id->display(),
            domain: $domain,
            kind: strtolower($type),
            synonyms: \array_slice($names, 1),
            description: $description,
            properties: $properties,
            identifiers: new Identifiers($identifiers),
            url: $id->url(),
            image: $image,
            source: 'kegg',
            related: array_filter($related, static fn (Identifiers $i) => \count($i) > 0),
        );
    }

    /** A text of KEGG's, its white space collapsed: not HTML ("<=>" is an equation's), its entities decoded. */
    private static function plain(?string $text): ?string
    {
        $text = trim((string) preg_replace('~\s+~u', ' ', html_entity_decode((string) $text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));

        return '' === $text ? null : $text;
    }

    /** One line of find: "C07481<TAB>Caffeine; 1,3,7-Trimethylxanthine". */
    private function fromFind(string $database, string $line): ?Entity
    {
        if (!str_contains($line, "\t")) {
            return null;
        }
        [$key, $names] = explode("\t", $line, 2);
        $key = (string) preg_replace('~^(?:cpd|dr|rn|path|ec):~', '', trim($key));
        $id = match ($database) {
            'compound', 'drug' => Identifier::tryOf(Scheme::KEGG, $key),
            'reaction' => Identifier::tryOf(Scheme::KEGG_REACTION, $key),
            'pathway' => Identifier::tryOf(Scheme::KEGG_PATHWAY, $key),
            'enzyme' => Identifier::tryOf(Scheme::EC, $key),
        };
        if (null === $id) {
            return null;
        }
        $list = array_values(array_filter(array_map(static fn (string $n) => self::plain($n), explode(';', $names))));

        return new Entity(
            id: $id,
            label: $list[0] ?? $id->display(),
            domain: self::DATABASES[$database],
            kind: $database,
            synonyms: \array_slice($list, 1),
            url: $id->url(),
            source: 'kegg',
        );
    }
}
