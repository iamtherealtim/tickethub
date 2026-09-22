<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;

/**
 * Shared plumbing for the JSON API: body parsing, error shape, pagination.
 *
 * Every list is {data: [...], page, per_page, total}; every error is
 * {error: "..."} with a meaningful status code. Scoping comes from the token's
 * user through BaseController, exactly as the UI applies it.
 */
abstract class ApiController extends BaseController
{
    protected const PER_PAGE_MAX = 100;

    /**
     * Decoded request body, or null when the caller sent JSON that does not parse.
     * Form-encoded callers are tolerated too; the shape is identical either way.
     */
    protected function json(): ?array
    {
        try {
            $body = $this->request->getJSON(true);
        } catch (\Throwable $e) {
            return null;
        }
        if ($body === null && trim((string) $this->request->getBody()) !== ''
            && str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'json')) {
            return null; // e.g. a bare "null" or a body the framework declined to parse
        }
        if (is_array($body)) {
            return $body;
        }
        // PATCH bodies are not parsed into getPost(); read the raw form body.
        $raw = (string) $this->request->getBody();
        if ($raw !== '' && ! str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'json')) {
            parse_str($raw, $out);

            return $out;
        }

        return (array) $this->request->getPost();
    }

    protected function fail(string $message, int $code)
    {
        return $this->response->setStatusCode($code)->setJSON(['error' => $message]);
    }

    protected function ok(array $data, int $code = 200)
    {
        return $this->response->setStatusCode($code)->setJSON($data);
    }

    /** [page, perPage] from the query string, clamped. */
    protected function paging(): array
    {
        return [
            max(1, (int) ($this->request->getGet('page') ?: 1)),
            min(static::PER_PAGE_MAX, max(1, (int) ($this->request->getGet('per_page') ?: 25))),
        ];
    }

    /** Paginate a query builder: runs the count, then the page. */
    protected function paginate($builder, callable $shape, string $orderBy = 'id', string $dir = 'DESC')
    {
        [$page, $perPage] = $this->paging();
        $total = (int) $builder->countAllResults(false);
        $rows  = $builder->orderBy($orderBy, $dir)->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return $this->ok([
            'data' => array_map($shape, $rows),
            'page' => $page, 'per_page' => $perPage, 'total' => $total,
        ]);
    }

    /** Paginate an already-filtered PHP array (used where scoping is done in PHP). */
    protected function paginateArray(array $rows, callable $shape)
    {
        [$page, $perPage] = $this->paging();

        return $this->ok([
            'data' => array_map($shape, array_slice(array_values($rows), ($page - 1) * $perPage, $perPage)),
            'page' => $page, 'per_page' => $perPage, 'total' => count($rows),
            'meta' => ['total' => count($rows), 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    protected function isAgent(): bool
    {
        return $this->isAgentRole();
    }

    protected function canManage(): bool
    {
        return in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true);
    }

    protected function pick(?string $value, array $allowed, ?string $fallback): ?string
    {
        return $value !== null && in_array($value, $allowed, true) ? $value : $fallback;
    }

    /** Small public view of a user for embedding in other resources. */
    protected function userRef(?int $id): ?array
    {
        $u = $this->userById($id);

        return $u ? ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email']] : null;
    }
}
