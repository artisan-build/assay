<?php

declare(strict_types=1);

namespace App\Providers;

use App\Mcp\AssayMcpServer;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

final class AssayMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config()->set([
            'built-for-cloud.mcp.path' => '/mcp',
            'built-for-cloud.mcp.write_path' => null,
            'built-for-cloud.mcp.destructive_path' => '/mcp/destructive',
            'built-for-cloud.mcp.delegated' => true,
        ]);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            Mcp::web('/mcp', AssayMcpServer::class)
                ->middleware('bfc.mcp:product,read');
            Mcp::web('/mcp/destructive', AssayMcpServer::class)
                ->middleware('bfc.mcp:product,destructive');
        });
    }
}
