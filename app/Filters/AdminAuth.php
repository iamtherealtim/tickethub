<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AdminAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();
        if (! $session->get('user_id')) {
            return redirect()->to('/login');
        }
        if ($session->get('role') !== 'Administrator') {
            $session->setFlashdata('toast', ['msg' => 'Admin is restricted to administrators', 'kind' => 'warn']);

            return redirect()->to(in_array($session->get('role'), ['Supervisor', 'Agent'], true) ? '/app/dashboard' : '/portal');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
