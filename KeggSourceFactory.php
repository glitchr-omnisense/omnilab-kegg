<?php

namespace Omnilab\Kegg;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * KEGG: compounds, drugs, reactions, pathways and enzymes by their KEGG
 * identifiers, their cross-references, and a search - no key, but KEGG's
 * API is for academic use by academic users only (see the README).
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     databases: ['compound']          # what search() searches: compound, drug, reaction, pathway, enzyme
 *     throttle: 0.34                   # seconds between two calls: KEGG blocks a caller above three a second
 *     base_uri: 'https://rest.kegg.jp/'
 */
final class KeggSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'kegg',
            'omnilab.required_options' => [],
            'databases' => ['compound'],
            'throttle' => 0.34,
            'base_uri' => KeggSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $databases = $c->get('databases');

        return new KeggSource(
            $this->http ?? HttpClient::create(),
            array_values(array_map('strval', \is_array($databases) ? $databases : [$databases])),
            (string) $c->get('base_uri', KeggSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
