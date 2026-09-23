<?php
/** Admin → Identity & 2FA tab body. Vars: $settings, $groups, $me, $inputCls, $mfaUsers, $ldapAvailable, $oidcCallback, $samlAvailable, $samlAcsUrl, $samlMetadataUrl, $samlEntityId. */
$s = static fn (string $k, string $d = '') => esc($settings[$k] ?? $d, 'attr');
$mfaPolicy = $settings['mfa_required_roles'] ?? 'none';
$oidcOn = ($settings['oidc_enabled'] ?? '0') === '1';
$ldapOn = ($settings['ldap_enabled'] ?? '0') === '1';
$samlOn = ($settings['saml_enabled'] ?? '0') === '1';
$lbl = 'block text-[12px] font-medium text-ink-500 mb-1.5';
$hint = 'text-[11.5px] text-faint mt-1';
$check = 'w-[15px] h-[15px] rounded border-line';
$footer = 'flex items-center gap-2 px-4 py-3.5 border-t border-line bg-canvas rounded-b-xl';
$primary = 'h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold';
$ghost = 'h-9 px-3.5 rounded-lg border border-line bg-white text-[13px] font-medium text-ink-500 hover:bg-canvas';
$ssoPolicy = $settings['sso_required_roles'] ?? 'none';
$anySsoOn = ($settings['azure_enabled'] ?? '0') === '1' || $oidcOn || $ldapOn || $samlOn;
?>
<div class="space-y-4">

  <!-- ============ Require single sign-on ============ -->
  <form method="post" action="<?= site_url('app/admin/identity/sso-required') ?>">
    <?= csrf_field() ?>
    <section class="bg-white border border-line rounded-xl shadow-card">
      <?= th_card_head('Require single sign-on', '<span class="' . ($ssoPolicy !== 'none' ? 'text-brand' : 'text-faint') . ' font-semibold">' . ($ssoPolicy === 'all' ? 'Required for everyone' : ($ssoPolicy === 'agents' ? 'Required for agents' : 'Optional'))  . '</span>') ?>
      <div class="p-4 grid sm:grid-cols-2 gap-3.5">
        <div>
          <label class="<?= $lbl ?>">Who must sign in through SSO or the directory</label>
          <select name="sso_required_roles" class="<?= $inputCls ?>">
            <?php foreach (['none' => 'Optional — local passwords always work', 'agents' => 'Required for Agents and Supervisors', 'all' => 'Required for everyone, including requesters'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= $ssoPolicy === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach ?>
          </select>
          <?php if ($ssoPolicy !== 'none' && ! $anySsoOn): ?>
          <p class="text-[11.5px] text-alert mt-1 flex items-start gap-1.5"><?= th_icon('warn', 'w-3.5 h-3.5 mt-0.5') ?> No SSO method below is enabled yet — people in scope will not be able to sign in at all until you turn one on.</p>
          <?php else: ?>
          <p class="<?= $hint ?>">A correct local password stops working for accounts in scope; they use one of the buttons on the login page instead, or the directory when LDAP is on.</p>
          <?php endif ?>
        </div>
        <div class="rounded-lg border border-line bg-canvas p-3 text-[12.5px] text-ink-500 leading-relaxed">
          Administrators are always exempt, however this is set. That is deliberate: a broken or unreachable identity provider must never be able to lock every admin out of a self-hosted instance with no way back in.
        </div>
      </div>
      <div class="<?= $footer ?>">
        <button type="submit" class="<?= $primary ?>">Save policy</button>
      </div>
    </section>
  </form>

  <!-- ============ Two-factor policy ============ -->
  <form method="post" action="<?= site_url('app/admin/identity/mfa') ?>">
    <?= csrf_field() ?>
    <section class="bg-white border border-line rounded-xl shadow-card">
      <?= th_card_head('Two-factor authentication', '<span class="' . ($mfaPolicy !== 'none' ? 'text-brand' : 'text-faint') . ' font-semibold">' . ($mfaPolicy === 'all' ? 'Required for everyone' : ($mfaPolicy === 'agents' ? 'Required for agents' : 'Optional'))  . '</span>') ?>
      <div class="p-4 grid sm:grid-cols-2 gap-3.5">
        <div>
          <label class="<?= $lbl ?>">Who must use an authenticator app</label>
          <select name="mfa_required_roles" class="<?= $inputCls ?>">
            <?php foreach (['none' => 'Optional — anyone may enrol', 'agents' => 'Required for Agents, Supervisors and Administrators', 'all' => 'Required for everyone, including requesters'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= $mfaPolicy === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach ?>
          </select>
          <p class="<?= $hint ?>">People in scope without 2FA are sent to Account → Security until they enrol. Everyone can turn it on for themselves at <code class="font-mono"><?= site_url('account/security') ?></code>.</p>
        </div>
        <div class="rounded-lg border border-line bg-canvas p-3 text-[12.5px] text-ink-500 leading-relaxed">
          Codes are TOTP (RFC 6238): 6 digits, 30-second step, ±30 s of clock drift allowed. Each enrolment also issues 10 one-time recovery codes. Secrets are <?= \App\Libraries\Settings::encryptionAvailable() ? 'encrypted at rest with encryption.key' : '<b>stored in plaintext — set encryption.key in .env</b>' ?>.
        </div>
      </div>
      <div class="<?= $footer ?>">
        <span class="text-[12px] text-muted"><?= count($mfaUsers) ?> account<?= count($mfaUsers) === 1 ? '' : 's' ?> currently enrolled</span>
        <div class="flex-1"></div>
        <button type="submit" class="<?= $primary ?>">Save policy</button>
      </div>
    </section>
  </form>

  <?php if ($mfaUsers): ?>
  <section class="bg-white border border-line rounded-xl shadow-card">
    <?= th_card_head('Enrolled accounts', '<span class="text-muted">Reset when someone loses their phone and their recovery codes</span>') ?>
    <?= th_table_head([['Person', 'flex-1'], ['Role', 'w-[120px]'], ['Enrolled', 'w-[150px]'], ['', 'w-[110px] text-right']]) ?>
    <?php foreach ($mfaUsers as $u): ?>
    <div class="flex items-center gap-3 px-4 py-2.5 border-b border-line last:border-0 text-[13px]">
      <div class="flex-1 min-w-0"><div class="text-ink truncate"><?= esc($u['name']) ?></div><div class="text-[11.5px] text-faint truncate"><?= esc($u['email']) ?></div></div>
      <div class="w-[120px] text-muted"><?= esc($u['role']) ?></div>
      <div class="w-[150px] text-muted"><?= esc(th_date($u['totp_enabled_at'])) ?></div>
      <div class="w-[110px] text-right">
        <form method="post" action="<?= site_url('app/admin/identity/users/' . (int) $u['id'] . '/reset-2fa') ?>" class="inline" onsubmit="return confirm('Reset two-factor for <?= esc($u['name'], 'js') ?>? They will sign in with their password alone until they enrol again.')">
          <?= csrf_field() ?><?= th_btn('Reset 2FA', 'type="submit"', 'danger', 'refresh') ?>
        </form>
      </div>
    </div>
    <?php endforeach ?>
  </section>
  <?php endif ?>

  <!-- ============ OpenID Connect ============ -->
  <form method="post" action="<?= site_url('app/admin/identity/oidc') ?>" id="oidcForm">
    <?= csrf_field() ?>
    <section class="bg-white border border-line rounded-xl shadow-card">
      <?= th_card_head('OpenID Connect single sign-on', '<span class="' . ($oidcOn ? 'text-brand' : 'text-faint') . ' font-semibold">' . ($oidcOn ? 'Enabled' : 'Disabled') . '</span>') ?>
      <div class="p-4 grid sm:grid-cols-2 gap-3.5">
        <label class="sm:col-span-2 flex items-center gap-2.5 rounded-lg border border-line bg-canvas p-3 cursor-pointer">
          <input type="checkbox" name="oidc_enabled" value="1" <?= $oidcOn ? 'checked' : '' ?> class="<?= $check ?>">
          <span class="text-[13px] font-medium text-ink">Show the single sign-on button on the login page</span>
          <span class="text-[12px] text-muted">— works with Google Workspace, Okta, Keycloak, Auth0, JumpCloud or any OIDC provider with a discovery document</span>
        </label>

        <div class="sm:col-span-2 flex flex-wrap items-center gap-2">
          <span class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint">Presets</span>
          <button type="button" class="<?= $ghost ?> h-8 text-[12.5px]" data-preset="google">Google Workspace</button>
          <button type="button" class="<?= $ghost ?> h-8 text-[12.5px]" data-preset="okta">Okta</button>
          <button type="button" class="<?= $ghost ?> h-8 text-[12.5px]" data-preset="keycloak">Keycloak</button>
        </div>

        <div class="sm:col-span-2">
          <label class="<?= $lbl ?>">Issuer URL</label>
          <input name="oidc_issuer" id="oidc_issuer" value="<?= $s('oidc_issuer') ?>" placeholder="https://accounts.google.com" class="<?= $inputCls ?> font-mono text-[12px]">
          <p class="<?= $hint ?>">TicketHub reads <code class="font-mono">&lt;issuer&gt;/.well-known/openid-configuration</code> (cached for an hour) to find the endpoints and signing keys.</p>
        </div>
        <div>
          <label class="<?= $lbl ?>">Client ID</label>
          <input name="oidc_client_id" value="<?= $s('oidc_client_id') ?>" class="<?= $inputCls ?> font-mono text-[12px]">
        </div>
        <div>
          <label class="<?= $lbl ?>">Client secret</label>
          <input name="oidc_client_secret" type="password" value="" autocomplete="new-password" placeholder="<?= ($settings['oidc_client_secret'] ?? '') !== '' ? '•••••••• (saved — leave blank to keep)' : 'From the provider console' ?>" class="<?= $inputCls ?>">
        </div>
        <div>
          <label class="<?= $lbl ?>">Scopes</label>
          <input name="oidc_scopes" id="oidc_scopes" value="<?= $s('oidc_scopes', 'openid profile email') ?>" class="<?= $inputCls ?> font-mono text-[12px]">
          <p class="<?= $hint ?>">Add <code class="font-mono">groups</code> if your provider needs it for role mapping.</p>
        </div>
        <div>
          <label class="<?= $lbl ?>">Button label</label>
          <input name="oidc_button_label" id="oidc_button_label" value="<?= $s('oidc_button_label') ?>" placeholder="Sign in with Google" maxlength="40" class="<?= $inputCls ?>">
        </div>

        <div class="sm:col-span-2 text-[11px] font-semibold uppercase tracking-[.09em] text-faint mt-1">Role mapping from claims (optional)</div>
        <div class="grid grid-cols-[1fr_1fr] gap-2">
          <div><label class="<?= $lbl ?>">Agent claim</label><input name="oidc_agent_claim" value="<?= $s('oidc_agent_claim') ?>" placeholder="groups" class="<?= $inputCls ?> font-mono text-[12px]"></div>
          <div><label class="<?= $lbl ?>">contains value</label><input name="oidc_agent_value" value="<?= $s('oidc_agent_value') ?>" placeholder="servicedesk" class="<?= $inputCls ?> font-mono text-[12px]"></div>
        </div>
        <div class="grid grid-cols-[1fr_1fr] gap-2">
          <div><label class="<?= $lbl ?>">Administrator claim</label><input name="oidc_admin_claim" value="<?= $s('oidc_admin_claim') ?>" placeholder="groups" class="<?= $inputCls ?> font-mono text-[12px]"></div>
          <div><label class="<?= $lbl ?>">contains value</label><input name="oidc_admin_value" value="<?= $s('oidc_admin_value') ?>" placeholder="it-admins" class="<?= $inputCls ?> font-mono text-[12px]"></div>
        </div>
        <p class="sm:col-span-2 <?= $hint ?> !mt-0">A claim can be a list (<code class="font-mono">groups</code>) or a single value (<code class="font-mono">hd</code> = <code class="font-mono">example.com</code> for Google). With either mapping set, everyone else becomes a Requester on their next sign-in; Supervisors and the last Administrator are never demoted. Leave both blank to keep roles as set under Agents &amp; roles.</p>

        <div class="sm:col-span-2">
          <label class="<?= $lbl ?>">Allowed email domains</label>
          <input name="oidc_allowed_domains" value="<?= $s('oidc_allowed_domains') ?>" placeholder="example.com" class="<?= $inputCls ?> font-mono text-[12px]">
          <?php $publicIssuer = str_contains(strtolower($settings['oidc_issuer'] ?? ''), 'accounts.google.com'); ?>
          <?php if ($publicIssuer && trim($settings['oidc_allowed_domains'] ?? '') === ''): ?>
          <p class="text-[11.5px] text-alert mt-1">With Google as the issuer and no domain here, any Google account in the world can create a portal account. Enter your Workspace domain.</p>
          <?php else: ?>
          <p class="<?= $hint ?>">Only these domains may sign in through OIDC. Existing accounts are linked only when the provider confirms the email (<code class="font-mono">email_verified</code>), and each is bound to its <code class="font-mono">sub</code> on first sign-in.</p>
          <?php endif ?>
        </div>

        <div class="sm:col-span-2 rounded-lg border border-line bg-canvas p-3">
          <div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint mb-1.5">Provider setup</div>
          <ol class="text-[12.5px] text-ink-500 leading-relaxed list-decimal ml-4 space-y-1">
            <li>Create a <b>Web</b> OAuth client with the provider (Google: Cloud Console → APIs &amp; Services → Credentials).</li>
            <li>Add this redirect URI: <code class="font-mono text-[11.5px] bg-white border border-line rounded px-1.5 py-0.5 select-all"><?= esc($oidcCallback) ?></code></li>
            <li>Paste the client ID and secret above. New people are created as Requesters on first sign-in (email match signs existing accounts in).</li>
          </ol>
        </div>
      </div>
      <div class="<?= $footer ?>">
        <span class="text-[12px] text-muted">Authorization code + PKCE; id_tokens are verified against the provider's JWKS.</span>
        <div class="flex-1"></div>
        <button type="submit" form="oidcTest" class="<?= $ghost ?>">Test discovery</button>
        <button type="submit" class="<?= $primary ?>">Save settings</button>
      </div>
    </section>
  </form>
  <form method="post" action="<?= site_url('app/admin/identity/oidc/test') ?>" id="oidcTest" class="hidden"><?= csrf_field() ?></form>

  <!-- ============ SAML 2.0 ============ -->
  <form method="post" action="<?= site_url('app/admin/identity/saml') ?>">
    <?= csrf_field() ?>
    <section class="bg-white border border-line rounded-xl shadow-card">
      <?= th_card_head('SAML 2.0 single sign-on', '<span class="' . ($samlOn && $samlAvailable ? 'text-brand' : 'text-faint') . ' font-semibold">' . ($samlAvailable ? ($samlOn ? 'Enabled' : 'Disabled') : 'Unavailable') . '</span>') ?>
      <?php if (! $samlAvailable): ?>
      <div class="mx-4 mt-4 rounded-lg border border-alert/40 bg-alert/5 px-3.5 py-3 text-[13px] text-ink flex items-start gap-2.5">
        <span class="text-alert mt-0.5"><?= th_icon('warn', 'w-4 h-4') ?></span>
        <div><b>onelogin/php-saml is not installed.</b> <span class="text-muted">Run <code class="font-mono">composer install</code> on this server (the Docker image already includes it). The settings below are kept but sign-in stays off until then.</span></div>
      </div>
      <?php endif ?>
      <fieldset <?= $samlAvailable ? '' : 'disabled' ?> class="<?= $samlAvailable ? '' : 'opacity-60' ?>">
      <div class="p-4 grid sm:grid-cols-2 gap-3.5">
        <label class="sm:col-span-2 flex items-center gap-2.5 rounded-lg border border-line bg-canvas p-3 cursor-pointer">
          <input type="checkbox" name="saml_enabled" value="1" <?= $samlOn ? 'checked' : '' ?> class="<?= $check ?>">
          <span class="text-[13px] font-medium text-ink">Show the SAML single sign-on button on the login page</span>
          <span class="text-[12px] text-muted">— for Okta, OneLogin, Azure AD (SAML app), ADFS, PingFederate or any SAML 2.0 identity provider</span>
        </label>

        <div class="sm:col-span-2 rounded-lg border border-line bg-canvas p-3">
          <label class="<?= $lbl ?>">Import from the identity provider's metadata URL</label>
          <div class="flex flex-wrap gap-2">
            <input form="samlFetch" name="saml_metadata_url" placeholder="https://idp.example.com/metadata" class="<?= $inputCls ?> font-mono text-[12px] flex-1 min-w-[240px]">
            <button type="submit" form="samlFetch" class="<?= $ghost ?> h-9">Fetch &amp; fill in</button>
          </div>
          <p class="<?= $hint ?>">Fetched over HTTPS with certificate validation, then the fields below are filled in from it. You can also fill them in by hand.</p>
        </div>

        <div class="sm:col-span-2">
          <label class="<?= $lbl ?>">Identity provider entity ID</label>
          <input name="saml_idp_entity_id" value="<?= $s('saml_idp_entity_id') ?>" placeholder="https://idp.example.com/entity" class="<?= $inputCls ?> font-mono text-[12px]">
        </div>
        <div class="sm:col-span-2">
          <label class="<?= $lbl ?>">Identity provider SSO URL</label>
          <input name="saml_idp_sso_url" value="<?= $s('saml_idp_sso_url') ?>" placeholder="https://idp.example.com/sso/saml" class="<?= $inputCls ?> font-mono text-[12px]">
        </div>
        <div class="sm:col-span-2">
          <label class="<?= $lbl ?>">Identity provider signing certificate</label>
          <textarea name="saml_idp_x509cert" rows="4" placeholder="-----BEGIN CERTIFICATE-----" class="<?= $inputCls ?> font-mono text-[11.5px] leading-snug"><?= esc($settings['saml_idp_x509cert'] ?? '') ?></textarea>
          <p class="<?= $hint ?>">PEM format, with or without the BEGIN/END lines. TicketHub never trusts a certificate carried inside a message — only the one saved here.</p>
        </div>
        <div>
          <label class="<?= $lbl ?>">Our entity ID (optional override)</label>
          <input name="saml_sp_entity_id" value="<?= $s('saml_sp_entity_id') ?>" placeholder="<?= esc($samlEntityId) ?>" class="<?= $inputCls ?> font-mono text-[12px]">
          <p class="<?= $hint ?>">Leave blank to use the metadata URL below, which is what most providers expect.</p>
        </div>
        <div>
          <label class="<?= $lbl ?>">Button label</label>
          <input name="saml_button_label" value="<?= $s('saml_button_label') ?>" placeholder="Sign in with SSO" maxlength="40" class="<?= $inputCls ?>">
        </div>

        <div class="sm:col-span-2 text-[11px] font-semibold uppercase tracking-[.09em] text-faint mt-1">Attribute mapping (optional)</div>
        <div>
          <label class="<?= $lbl ?>">Email attribute</label>
          <input name="saml_attr_email" value="<?= $s('saml_attr_email') ?>" placeholder="leave blank to use the NameID" class="<?= $inputCls ?> font-mono text-[12px]">
        </div>
        <div>
          <label class="<?= $lbl ?>">Name attribute</label>
          <input name="saml_attr_name" value="<?= $s('saml_attr_name') ?>" placeholder="displayName" class="<?= $inputCls ?> font-mono text-[12px]">
        </div>

        <div class="sm:col-span-2 text-[11px] font-semibold uppercase tracking-[.09em] text-faint mt-1">Role mapping from attributes (optional)</div>
        <div class="grid grid-cols-[1fr_1fr] gap-2">
          <div><label class="<?= $lbl ?>">Agent attribute</label><input name="saml_agent_attr" value="<?= $s('saml_agent_attr') ?>" placeholder="groups" class="<?= $inputCls ?> font-mono text-[12px]"></div>
          <div><label class="<?= $lbl ?>">contains value</label><input name="saml_agent_value" value="<?= $s('saml_agent_value') ?>" placeholder="servicedesk" class="<?= $inputCls ?> font-mono text-[12px]"></div>
        </div>
        <div class="grid grid-cols-[1fr_1fr] gap-2">
          <div><label class="<?= $lbl ?>">Administrator attribute</label><input name="saml_admin_attr" value="<?= $s('saml_admin_attr') ?>" placeholder="groups" class="<?= $inputCls ?> font-mono text-[12px]"></div>
          <div><label class="<?= $lbl ?>">contains value</label><input name="saml_admin_value" value="<?= $s('saml_admin_value') ?>" placeholder="it-admins" class="<?= $inputCls ?> font-mono text-[12px]"></div>
        </div>
        <p class="sm:col-span-2 <?= $hint ?> !mt-0">With either mapping set, everyone else becomes a Requester on their next sign-in; Supervisors and the last Administrator are never demoted. Leave both blank to keep roles as set under Agents &amp; roles.</p>

        <div class="sm:col-span-2">
          <label class="<?= $lbl ?>">Allowed email domains (optional)</label>
          <input name="saml_allowed_domains" value="<?= $s('saml_allowed_domains') ?>" placeholder="example.com, example.co.uk" class="<?= $inputCls ?> font-mono text-[12px]">
          <p class="<?= $hint ?>">When set, only these domains may sign in through SAML. Each account is bound to its identity-provider subject on first sign-in; a different subject claiming the same email is refused.</p>
        </div>
        <label class="sm:col-span-2 flex items-center gap-2.5 rounded-lg border border-line bg-canvas p-3 cursor-pointer">
          <input type="checkbox" name="saml_allow_idp_initiated" value="1" <?= ($settings['saml_allow_idp_initiated'] ?? '0') === '1' ? 'checked' : '' ?> class="<?= $check ?>">
          <span class="text-[13px] font-medium text-ink">Allow sign-in started from the identity provider's app dashboard</span>
          <span class="text-[12px] text-muted">— off by default: unsolicited responses are refused, which blocks forced-login and replay tricks. Turn on only if people launch TicketHub from an IdP tile.</span>
        </label>

        <div class="sm:col-span-2 rounded-lg border border-line bg-canvas p-3">
          <div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint mb-1.5">Provider setup</div>
          <ol class="text-[12.5px] text-ink-500 leading-relaxed list-decimal ml-4 space-y-1">
            <li>Create a SAML application at the provider and give it our metadata, either as a URL or by downloading it:
              <div class="mt-1"><code class="font-mono text-[11.5px] bg-white border border-line rounded px-1.5 py-0.5 select-all"><?= esc($samlMetadataUrl) ?></code></div>
            </li>
            <li>Or configure it by hand — ACS URL (Reply URL) and Entity ID:
              <div class="mt-1 space-y-1">
                <div><code class="font-mono text-[11.5px] bg-white border border-line rounded px-1.5 py-0.5 select-all"><?= esc($samlAcsUrl) ?></code></div>
                <div><code class="font-mono text-[11.5px] bg-white border border-line rounded px-1.5 py-0.5 select-all"><?= esc($samlEntityId) ?></code></div>
              </div>
            </li>
            <li>Send the email address in the NameID (format: emailAddress), or map an attribute above. New people are created as Requesters on first sign-in (email match signs existing accounts in).</li>
          </ol>
        </div>
      </div>
      <div class="<?= $footer ?>">
        <span class="text-[12px] text-muted">Signed assertions only — TicketHub never accepts an unsigned response.</span>
        <div class="flex-1"></div>
        <button type="submit" class="<?= $primary ?>">Save settings</button>
      </div>
      </fieldset>
    </section>
  </form>
  <form method="post" action="<?= site_url('app/admin/identity/saml/fetch-metadata') ?>" id="samlFetch"><?= csrf_field() ?></form>

  <!-- ============ LDAP / Active Directory ============ -->
  <form method="post" action="<?= site_url('app/admin/identity/ldap') ?>">
    <?= csrf_field() ?>
    <section class="bg-white border border-line rounded-xl shadow-card">
      <?= th_card_head('LDAP / Active Directory', '<span class="' . ($ldapOn && $ldapAvailable ? 'text-brand' : 'text-faint') . ' font-semibold">' . ($ldapAvailable ? ($ldapOn ? 'Enabled' : 'Disabled') : 'Unavailable') . '</span>') ?>
      <?php if (! $ldapAvailable): ?>
      <div class="mx-4 mt-4 rounded-lg border border-alert/40 bg-alert/5 px-3.5 py-3 text-[13px] text-ink flex items-start gap-2.5">
        <span class="text-alert mt-0.5"><?= th_icon('warn', 'w-4 h-4') ?></span>
        <div><b>php-ldap extension not installed.</b> <span class="text-muted">Directory sign-in cannot run on this server. Enable <code class="font-mono">extension=ldap</code> in php.ini (or install <code class="font-mono">php-ldap</code>) and restart PHP; the settings below are kept but the form is disabled until then.</span></div>
      </div>
      <?php endif ?>
      <fieldset <?= $ldapAvailable ? '' : 'disabled' ?> class="<?= $ldapAvailable ? '' : 'opacity-60' ?>">
      <div class="p-4 grid sm:grid-cols-2 gap-3.5">
        <label class="sm:col-span-2 flex items-center gap-2.5 rounded-lg border border-line bg-canvas p-3 cursor-pointer">
          <input type="checkbox" name="ldap_enabled" value="1" <?= $ldapOn ? 'checked' : '' ?> class="<?= $check ?>">
          <span class="text-[13px] font-medium text-ink">Check the directory when someone signs in with a password</span>
          <span class="text-[12px] text-muted">— unknown emails and accounts marked <code class="font-mono">ldap</code> are verified against the directory; local accounts keep their local password</span>
        </label>
        <div class="grid grid-cols-[1fr_90px_130px] gap-2 sm:col-span-2">
          <div><label class="<?= $lbl ?>">Host</label><input name="ldap_host" value="<?= $s('ldap_host') ?>" placeholder="dc01.corp.example.com" class="<?= $inputCls ?> font-mono text-[12px]"></div>
          <div><label class="<?= $lbl ?>">Port</label><input name="ldap_port" value="<?= $s('ldap_port') ?>" placeholder="389" inputmode="numeric" class="<?= $inputCls ?> font-mono text-[12px]"></div>
          <div><label class="<?= $lbl ?>">Encryption</label><select name="ldap_encryption" class="<?= $inputCls ?>"><?php foreach (['none' => 'None', 'starttls' => 'StartTLS', 'ldaps' => 'LDAPS (636)'] as $v => $l): ?><option value="<?= $v ?>" <?= ($settings['ldap_encryption'] ?? 'none') === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
        </div>
        <div>
          <label class="<?= $lbl ?>">Service account (bind DN or UPN)</label>
          <input name="ldap_bind_dn" value="<?= $s('ldap_bind_dn') ?>" placeholder="svc-tickethub@corp.example.com" class="<?= $inputCls ?> font-mono text-[12px]">
          <p class="<?= $hint ?>">Read-only account used to look people up. Leave blank for anonymous bind.</p>
        </div>
        <div>
          <label class="<?= $lbl ?>">Service account password</label>
          <input name="ldap_bind_password" type="password" value="" autocomplete="new-password" placeholder="<?= ($settings['ldap_bind_password'] ?? '') !== '' ? '•••••••• (saved — leave blank to keep)' : '' ?>" class="<?= $inputCls ?>">
        </div>
        <div class="sm:col-span-2">
          <label class="<?= $lbl ?>">Base DN</label>
          <input name="ldap_base_dn" value="<?= $s('ldap_base_dn') ?>" placeholder="OU=Staff,DC=corp,DC=example,DC=com" class="<?= $inputCls ?> font-mono text-[12px]">
        </div>
        <div class="sm:col-span-2">
          <label class="<?= $lbl ?>">User filter</label>
          <input name="ldap_user_filter" value="<?= $s('ldap_user_filter', \App\Libraries\Ldap::DEFAULT_FILTER) ?>" class="<?= $inputCls ?> font-mono text-[12px]">
          <p class="<?= $hint ?>"><code class="font-mono">{login}</code> is what the person typed in the email box (escaped). The default matches mail, sAMAccountName or uid.</p>
        </div>

        <div class="sm:col-span-2 text-[11px] font-semibold uppercase tracking-[.09em] text-faint mt-1">Attributes copied to the TicketHub profile</div>
        <div class="sm:col-span-2 grid grid-cols-2 md:grid-cols-5 gap-2">
          <?php foreach ([['ldap_attr_email', 'Email', 'mail'], ['ldap_attr_name', 'Name', 'displayName'], ['ldap_attr_title', 'Job title', 'title'], ['ldap_attr_phone', 'Phone', 'telephoneNumber'], ['ldap_attr_dept', 'Department', 'department']] as [$k, $label, $ph]): ?>
          <div><label class="<?= $lbl ?>"><?= $label ?></label><input name="<?= $k ?>" value="<?= $s($k) ?>" placeholder="<?= $ph ?>" class="<?= $inputCls ?> font-mono text-[12px]"></div>
          <?php endforeach ?>
        </div>

        <div class="sm:col-span-2 text-[11px] font-semibold uppercase tracking-[.09em] text-faint mt-1">Role mapping from memberOf (optional)</div>
        <div>
          <label class="<?= $lbl ?>">Agent group DN</label>
          <input name="ldap_agent_group_dn" value="<?= $s('ldap_agent_group_dn') ?>" placeholder="CN=Service Desk,OU=Groups,DC=corp,DC=example,DC=com" class="<?= $inputCls ?> font-mono text-[12px]">
        </div>
        <div>
          <label class="<?= $lbl ?>">Administrator group DN</label>
          <input name="ldap_admin_group_dn" value="<?= $s('ldap_admin_group_dn') ?>" placeholder="CN=IT Admins,OU=Groups,DC=corp,DC=example,DC=com" class="<?= $inputCls ?> font-mono text-[12px]">
        </div>
        <p class="sm:col-span-2 <?= $hint ?> !mt-0">Direct membership only (the <code class="font-mono">memberOf</code> attribute). With either group set, everyone else becomes a Requester on their next directory sign-in; Supervisors and the last Administrator are never demoted.</p>

        <label class="sm:col-span-2 flex items-center gap-2.5 rounded-lg border border-line bg-canvas p-3 cursor-pointer">
          <input type="checkbox" name="ldap_create_users" value="1" <?= ($settings['ldap_create_users'] ?? '1') === '1' ? 'checked' : '' ?> class="<?= $check ?>">
          <span class="text-[13px] font-medium text-ink">Create a TicketHub account on first directory sign-in</span>
          <span class="text-[12px] text-muted">— otherwise only people already invited can sign in</span>
        </label>
      </div>
      <div class="<?= $footer ?>">
        <span class="text-[12px] text-muted">Users are bound with their own password each time; TicketHub never stores it. Two-factor still applies after a directory sign-in.</span>
        <div class="flex-1"></div>
        <button type="submit" form="ldapTest" class="<?= $ghost ?>">Test connection</button>
        <button type="submit" class="<?= $primary ?>">Save settings</button>
      </div>
      </fieldset>
    </section>
  </form>
  <form method="post" action="<?= site_url('app/admin/identity/ldap/test') ?>" id="ldapTest" class="hidden"><?= csrf_field() ?></form>
</div>

<script>
(function () {
  var presets = {
    google:   { issuer: 'https://accounts.google.com', scopes: 'openid profile email', label: 'Sign in with Google' },
    okta:     { issuer: 'https://YOUR-ORG.okta.com', scopes: 'openid profile email groups', label: 'Sign in with Okta' },
    keycloak: { issuer: 'https://KEYCLOAK-HOST/realms/REALM', scopes: 'openid profile email', label: 'Sign in with SSO' }
  };
  document.querySelectorAll('#oidcForm [data-preset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = presets[b.dataset.preset];
      document.getElementById('oidc_issuer').value = p.issuer;
      document.getElementById('oidc_scopes').value = p.scopes;
      var l = document.getElementById('oidc_button_label');
      if (!l.value) l.value = p.label;
      document.getElementById('oidc_issuer').focus();
    });
  });
})();
</script>
