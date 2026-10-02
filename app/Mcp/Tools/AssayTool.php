<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\AssayCredentialAbility;
use App\Authorization\McpAccess;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

abstract class AssayTool extends Tool
{
    use RespectsEffectCeiling;

    abstract protected function ability(): AssayCredentialAbility;

    public function shouldRegister(McpAccess $access): bool
    {
        return $access->allows($this->ability());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }

    protected function authorize(): ActingPrincipal
    {
        return resolve(McpAccess::class)->authorize($this->ability());
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function validate(Request $request, array $rules, array $keys): array
    {
        $unexpected = array_values(array_diff(array_keys($request->all()), $keys));

        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'arguments' => 'Unexpected arguments: '.implode(', ', $unexpected).'.',
            ]);
        }

        return $request->validate($rules);
    }

    /** @param callable(): mixed $operation */
    protected function respond(callable $operation): Response
    {
        try {
            return Response::json($operation());
        } catch (NotFoundHttpException) {
            return Response::error('The requested Assay resource was not found.');
        } catch (ConflictHttpException) {
            return Response::error('The request conflicts with the current Assay state.');
        } catch (Throwable) {
            return Response::error('Assay could not complete the request.');
        }
    }
}
