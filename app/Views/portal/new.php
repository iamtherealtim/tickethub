<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>

<div class="max-w-[720px] mx-auto px-5 py-8 fade-in">
  <a href="<?= site_url('portal') ?>" class="inline-flex items-center gap-1 text-[12.5px] text-muted hover:text-ink mb-4"><?= th_icon('back', 'w-3.5 h-3.5') ?> Home</a>
  <h1 class="font-display text-[24px] font-semibold">Report a problem</h1>
  <p class="text-[13px] text-muted mt-1">Tell us what is not working. The more specific the first message, the fewer rounds it takes.</p>

  <form method="post" action="<?= site_url('portal/new') ?>" enctype="multipart/form-data" class="bg-white border border-line rounded-xl shadow-card p-5 mt-5 grid sm:grid-cols-2 gap-4">
    <?= csrf_field() ?>
    <div class="sm:col-span-2">
      <label class="block text-[12px] font-medium text-ink-500 mb-1.5">What is happening?<span class="text-alert"> *</span></label>
      <input name="subject" required placeholder="e.g. Cannot connect to VPN from home" autocomplete="off"
             data-kb-suggest="<?= site_url('portal/kb/suggest') ?>"
             class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] placeholder:text-faint focus:border-brand">
      <?php // Filled as they type; an answer here saves them raising anything. ?>
      <div data-kb-results class="mt-2 empty:mt-0"></div>
    </div>
    <div>
      <label class="block text-[12px] font-medium text-ink-500 mb-1.5">Area</label>
      <select name="category" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
        <?php foreach (TH_CATEGORIES as $c): ?><option><?= $c ?></option><?php endforeach ?>
      </select>
    </div>
    <div>
      <label class="block text-[12px] font-medium text-ink-500 mb-1.5">How much is it blocking you?</label>
      <select name="priority" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
        <option value="Low">I can work around it</option>
        <option value="Medium" selected>Slowing me down</option>
        <option value="High">I am blocked</option>
        <option value="Urgent">My whole team is blocked</option>
      </select>
    </div>
    <?php foreach ($customFields as $f): ?>
      <?php if ($f['type'] === 'Paragraph'): ?><div class="sm:col-span-2"><?= th_custom_field($f) ?></div>
      <?php else: ?><?= th_custom_field($f) ?><?php endif ?>
    <?php endforeach ?>
    <div class="sm:col-span-2">
      <label class="block text-[12px] font-medium text-ink-500 mb-1.5">Details<span class="text-alert"> *</span></label>
      <textarea name="body" rows="6" required placeholder="When it started, what you have tried, the exact error text, and whether anyone else is affected."
        class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed placeholder:text-faint focus:border-brand"></textarea>
    </div>
    <div class="sm:col-span-2">
      <label class="block text-[12px] font-medium text-ink-500 mb-1.5">Attach a screenshot or file</label>
      <input type="file" name="files[]" multiple class="w-full text-[12.5px] text-muted file:mr-3 file:h-8 file:px-3 file:rounded-lg file:border file:border-line file:bg-white file:text-[12.5px] file:font-medium file:text-ink-500 file:cursor-pointer">
      <p class="text-[11.5px] text-faint mt-1">Up to 10 MB each — images, PDF, Office, logs, zip.</p>
    </div>
    <div class="sm:col-span-2 flex flex-wrap items-center gap-2 pt-1">
      <div class="flex-1"></div>
      <a href="<?= site_url('portal') ?>" class="h-9 px-3.5 rounded-lg border border-line text-[13px] font-medium text-ink-500 hover:bg-canvas inline-flex items-center">Cancel</a>
      <button type="submit" class="h-9 px-4 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold">Send to the service desk</button>
    </div>
  </form>

  <p class="text-[12.5px] text-muted mt-4">Before you send — <a href="<?= site_url('portal/kb') ?>" class="text-brand font-medium hover:underline">check the knowledge base</a>. Password, VPN, and printing questions usually have an answer there.</p>
</div>
<?= $this->endSection() ?>
