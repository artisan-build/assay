<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\DatasetAdd;
use App\Mcp\Tools\DatasetCreate;
use App\Mcp\Tools\DatasetExport;
use App\Mcp\Tools\DatasetList;
use App\Mcp\Tools\DeleteSubject;
use App\Mcp\Tools\FlagRun;
use App\Mcp\Tools\LabelRun;
use App\Mcp\Tools\ReliabilitySummary;
use App\Mcp\Tools\RunContent;
use App\Mcp\Tools\RunTree;
use App\Mcp\Tools\SearchRuns;
use App\Mcp\Tools\TopRuns;
use App\Mcp\Tools\UsageSummary;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseCallTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Tool;

final class AssayMcpServer extends Server
{
    protected string $name = 'Assay';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Assay reports usage, not money.

        For cost questions, fetch current published provider pricing for every model and metric in the requested breakdown. State each source URL and its retrieval date. Label the result a current-list-price estimate of observed successful usage, not actual spend: historical price changes, negotiated discounts, and invoices are outside Assay. Disclose that the estimate excludes failed attempts, dropped telemetry and drop totals, and unreported metrics. Any gaps make the estimate a lower bound.

        Content tools export raw customer data to this MCP client and onward to the client's model provider, transcript, and logs. The default MCP credential is usage-only; content requires a separately granted scope.
        MARKDOWN;

    /** @var list<class-string<Tool>> */
    protected array $tools = [
        UsageSummary::class,
        TopRuns::class,
        ReliabilitySummary::class,
        RunTree::class,
        RunContent::class,
        SearchRuns::class,
        FlagRun::class,
        LabelRun::class,
        DatasetCreate::class,
        DatasetAdd::class,
        DatasetList::class,
        DatasetExport::class,
        DeleteSubject::class,
    ];

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->methods['tools/call'] = TwoPhaseCallTool::class;
    }
}
