<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

enum RecordType: string
{
    case RunStart = 'run.start';
    case RunEnd = 'run.end';
    case RunFailover = 'run.failover';
    case StepStart = 'step.start';
    case StepEnd = 'step.end';
    case StepFail = 'step.fail';
    case ToolStart = 'tool.start';
    case ToolEnd = 'tool.end';
    case ToolApproval = 'tool.approval';
    case ContentAttach = 'content.attach';
}
