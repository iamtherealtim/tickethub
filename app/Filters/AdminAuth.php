<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AdminAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = SessionUser::resolve();
        if (! $user) {
            return redirect()->to('/login');
        }
        if ($user['role'] !== 'Administrator') {
            session()->setFlashdata('toast', ['msg' => 'Admin is restricted to administrators', 'kind' => 'warn']);

            return redirect()->to(in_array($user['role'], ['Supervisor', 'Agent'], true) ? '/app/dashboard' : '/portal');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
