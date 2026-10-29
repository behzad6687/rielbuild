<?php
/** A list of FAQs (rows from Admin > FAQs) as open/close questions. Vars: $faqs, $openFirst (bool). */
defined('ICM') or exit;

foreach (array_values($faqs ?? []) as $i => $f): ?>
<details id="faq-<?= (int)$f['id'] ?>"<?= ($i === 0 && !empty($openFirst)) ? ' open' : '' ?>><summary><?= e($f['question']) ?></summary><div class="faq__a"><?= Content::prose((string)$f['answer_html']) ?></div></details>
<?php endforeach;
