<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

enum Operation: string
{
    case Agent = 'agent';
    case Embeddings = 'embeddings';
    case Image = 'image';
    case Audio = 'audio';
    case Transcription = 'transcription';
    case Reranking = 'reranking';
    case Classification = 'classification';
}
