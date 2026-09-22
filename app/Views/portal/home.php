<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>

<div class="fade-in">
  <section class="bg-ink text-white">
    <div class="max-w-[1120px] mx-auto px-5 py-12">
      <p class="text-[12px] uppercase tracking-[.16em] text-[#7C8698]">TicketHub service desk</p>
      <h1 class="font-display text-[34px] sm:text-[40px] font-semibold leading-[1.1] mt-3 max-w-[15ch]">What do you need help with, <?= esc(explode(' ', $me['name'])[0]) ?>?</h1>
      <div class="relative mt-6 max-w-[560px]">
        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[#7C8698]"><?= th_icon('search', 'w-[18px] h-[18px]') ?></span>
        <input id="portalSearch" data-search-url="<?= site_url('portal/search') ?>" placeholder="Search answers and services — try &ldquo;vpn&rdquo; or &ldquo;laptop&rdquo;" autocomplete="off"
          class="w-full h-12 pl-11 pr-4 rounded-xl bg-white/[.08] border border-white/15 text-[14px] text-white placeholder:text-[#7C8698] focus:bg-white focus:text-ink focus:border-white outline-none transition">
        <div id="portalResults" class="absolute left-0 right-0 top-14 bg-white rounded-xl shadow-pop border border-line overflow-hidden hidden z-20"></div>
      </div>
      <p class="text-[13px] text-[#7C8698] mt-3">Most password and VPN questions are answered in under a minute without raising a ticket.</p>
    </div>
  </section>

  <div class="max-w-[1120px] mx-auto px-5 py-8 space-y-8">
    <div class="grid sm:grid-cols-3 gap-3 -mt-14">
      <?php
        $quick = [
            [site_url('portal/new'), 'warn', 'Something is broken', 'Report a problem and we will pick it up straight away.'],
            [site_url('portal/catalog'), 'layers', 'Request something', 'Hardware, software, access, and onboarding.'],
            [site_url('portal/tickets'), 'inbox', 'Track a request', count($mine) . ' open right now.'],
        ];
      ?>
      <?php foreach ($quick as [$url, $ic, $t, $d]): ?>
      <a href="<?= $url ?>" class="text-left bg-white border border-line rounded-xl shadow-card p-4 hover:shadow-pop hover:border-brand-100 transition block">
        <span class="w-9 h-9 rounded-lg bg-brand-50 border border-brand-100 grid place-items-center text-brand"><?= th_icon($ic, 'w-[18px] h-[18px]') ?></span>
        <h3 class="font-display text-[15px] font-semibold mt-3"><?= $t ?></h3>
        <p class="text-[12.5px] text-muted mt-1 leading-relaxed"><?= $d ?></p></a>
      <?php endforeach ?>
    </div>

    <?php if ($announcements): ?>
    <section>
      <h2 class="font-display text-[16px] font-semibold mb-3">Service notices</h2>
      <div class="space-y-2">
        <?php foreach ($announcements as $a): ?>
        <div class="flex gap-3 bg-white border border-line rounded-xl p-4">
          <span class="w-1 rounded-full shrink-0 <?= $a['level'] === 'alert' ? 'bg-alert' : ($a['level'] === 'warn' ? 'bg-signal' : 'bg-brand') ?>"></span>
          <div><h3 class="text-[13.5px] font-semibold"><?= esc($a['title']) ?></h3>
            <p class="text-[13px] text-muted mt-1 leading-relaxed"><?= esc($a['body']) ?></p>
            <p class="text-[11.5px] text-faint mt-2"><?= th_rel($a['created_at']) ?></p></div>
        </div>
        <?php endforeach ?>
      </div>
    </section>
    <?php endif ?>

    <?php if ($mine): ?>
    <section>
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-display text-[16px] font-semibold">Your open requests</h2>
        <a href="<?= site_url('portal/tickets') ?>" class="text-[12.5px] text-brand font-medium hover:underline">See all</a>
      </div>
      <div class="bg-white border border-line rounded-xl overflow-hidden">
        <?php foreach (array_slice($mine, 0, 3) as $t): ?>
          <?= view('portal/_ticket_row', ['t' => $t, 'users' => $users]) ?>
        <?php endforeach ?>
      </div>
    </section>
    <?php endif ?>

    <section>
      <h2 class="font-display text-[16px] font-semibold mb-3">Answers people use most</h2>
      <div class="grid sm:grid-cols-2 gap-3">
        <?php foreach ($popular as $a): ?>
        <a href="<?= site_url('portal/kb/' . $a['id']) ?>" class="text-left bg-white border border-line rounded-xl p-4 hover:border-brand-100 transition block">
          <span class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint"><?= esc($a['category']) ?></span>
          <h3 class="font-display text-[14.5px] font-semibold mt-1.5 leading-snug"><?= esc($a['title']) ?></h3>
          <p class="font-mono text-[11.5px] text-faint mt-2"><?= (int) $a['up_votes'] ?> people found this helpful</p></a>
        <?php endforeach ?>
      </div>
    </section>
  </div>
</div>
<?= $this->endSection() ?>
