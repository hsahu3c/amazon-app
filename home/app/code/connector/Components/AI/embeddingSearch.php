<?php

namespace App\Connector\Components\AI;

use App\Core\Models\BaseMongo;

class embeddingSearch extends BaseMongo
{
    public function fetch($query, $filters = null, $target_marketplace = null)
    {

        $openAI = new \App\Connector\Api\OpenAI;

        $pinecone = new \App\Connector\Api\PineCone;

        $embedding = $openAI->createEmbedding(['model' => 'text-embedding-ada-002', 'input' => $query]);

        $vector =   $embedding['data'][0]['embedding'];

        $filters = $filters ? ['filter' => $filters] : [];

        $pineconeResults = $pinecone->querySearch([
            'namespace' => "{$target_marketplace}-catorgory",
            'topK' => 5, //TODO add in config
            'vector' => $vector,
            'includeMetadata' => true,
            'includeValues' => false,
        ] + $filters);

        return $pineconeResults['matches'];
    }
}
