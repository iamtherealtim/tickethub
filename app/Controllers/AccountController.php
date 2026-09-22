<?php

namespace App\Controllers;

use App\Libraries\Audit;

class AccountController extends BaseController
{
    /** Forced first-run password change (see App\Filters\MustChangePassword). */
    public function newPassword()
    {
        if (! $this->me) {
            return redirect()->to('/login');
        }
        if (! (int) $this->me['must_change_password']) {
            return redirect()->to($this->isAgentRole() ? '/app/dashboard' : '/portal');
        }

        return view('auth/new_password', ['me' => $this->me]);
    }

    public function password()
    {
        $current = (string) $this->request->getPost('current');
        $new     = (string) $this->request->getPost('password');
        $confirm = (string) $this->request->getPost('confirm');

        if (! password_verify($current, $this->me['password_hash'])) {
            $this->toast('Your current password is not right', 'warn');
        } elseif ($problem = self::passwordProblem($new, $this->me['email'])) {
            $this->toast($problem, 'warn');
        } elseif ($new !== $confirm) {
            $this->toast('The two new passwords do not match', 'warn');
        } else {
            $this->db->table('users')->where('id', $this->me['id'])->update([
                'password_hash' => password_hash($new, PASSWORD_DEFAULT),
                'must_change_password' => 0,
            ]);
            // A password change must evict remembered devices and stale sessions.
            $this->invalidateOtherSessions();
            Audit::log('password.changed', $this->me['email']);
            $this->toast('Password changed — other devices have been signed out');

            return redirect()->to($this->isAgentRole() ? '/app/dashboard' : '/portal');
        }

        return redirect()->back();
    }
}
