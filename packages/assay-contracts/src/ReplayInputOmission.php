<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

enum ReplayInputOmission: string
{
    case Attachments = 'attachments';
    case OutputSchema = 'output_schema';
    case ProviderOptions = 'provider_options';
    case ProviderReplayState = 'provider_replay_state';
}
