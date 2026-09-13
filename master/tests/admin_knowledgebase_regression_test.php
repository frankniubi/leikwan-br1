<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function requireSnippet(string $source, string $snippet, string $label): void
{
    if (!str_contains($source, $snippet)) {
        fail('missing ' . $label . ': ' . $snippet);
    }
}

function runAdminBehaviorTest(string $javascriptPath): void
{
    $behaviorTest = __DIR__ . '/admin_knowledgebase_behavior_test.js';
    if (!is_file($behaviorTest)) {
        fail('missing executable knowledgebase admin behavior test');
    }

    $output = [];
    $exitCode = 0;
    exec('node ' . escapeshellarg($behaviorTest) . ' ' . escapeshellarg($javascriptPath) . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        fail('knowledgebase admin behavior regression failed:' . PHP_EOL . implode(PHP_EOL, $output));
    }
}

$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$javascript = file_get_contents(__DIR__ . '/../public/assets/knowledgebase-admin.js');

if (!is_string($admin) || $admin === '') {
    fail('failed to read AdminController source');
}
if (!is_string($bootstrap) || $bootstrap === '') {
    fail('failed to read Bootstrap source');
}
if (!is_string($javascript) || $javascript === '') {
    fail('failed to read knowledgebase-admin.js source');
}

foreach ([
    'public function knowledgebaseTopicsPage(Request $request, array $params): Response',
    'public function knowledgebaseTopicEditorPage(Request $request, array $params): Response',
    'public function saveKnowledgebaseTopic(Request $request, array $params): Response',
    'public function deleteKnowledgebaseTopic(Request $request, array $params): Response',
    'public function knowledgebaseArticleEditorPage(Request $request, array $params): Response',
    'public function saveKnowledgebaseArticle(Request $request, array $params): Response',
    'public function previewKnowledgebaseArticle(Request $request, array $params): Response',
    'public function deleteKnowledgebaseArticle(Request $request, array $params): Response',
    'public function uploadKnowledgebaseImage(Request $request, array $params): Response',
    'listAdminTopics()',
    "['en', 'English', 'en_status']",
    "['zh-Hans', 'Simplified Chinese', 'zh_hans_status']",
    "['zh-TW', 'Traditional Chinese', 'zh_tw_status']",
    "'/knowledgebase?thread='",
    'Knowledgebase Topics',
    'Create Topic',
    'Edit Topic',
    'Edit Language Version',
    'Topic Key',
    'Language Versions',
    'Missing',
    'Draft',
    'Published',
    'Preview',
    'Save Topic',
    'Save Language Version',
    'Delete Topic',
    'Delete Language Version',
    'name="topic_key"',
    'name="sort_order"',
    'name="is_pinned"',
    'name="locale"',
    'name="mermaid_validation_digest"',
    'name="body_html" rows="24"',
    'formaction="/admin/knowledgebase/articles/preview"',
    'formtarget="_blank"',
    'formnovalidate',
    "extended_valid_elements: 'pre[class],img[src|alt|width|height]'",
    '/assets/vendor/mermaid/mermaid.min.js?v=11.12.0',
    '/assets/knowledgebase-admin.js?v=20260729',
    '/admin/knowledgebase/images',
    'paste_data_images: true',
    'new FormData()',
    'multipart/form-data',
    'SessionAuth::validateCsrf($request)',
    'ApiException $exception',
    '$exception->statusCode()',
    'LocaleResolver::normalize(',
    'new KnowledgebaseHtmlSanitizer()',
    'MermaidSourcePolicy::assertPreviewable(',
    'Response::html(200,',
    'Preview request expired. Reload the editor and try again.',
    'Preview could not be rendered. Check the article and diagrams.',
    '(new KnowledgebasePreviewDocument())->render(',
    'id="kb-topic-delete-confirm">Delete this topic and all three language versions?</span>',
    'id="kb-article-delete-confirm">Delete this language version?</span>',
    '.textContent',
    "'id' => \$request->input('id', 0)",
    "'topic_id' => \$request->input('topic_id', 0)",
    "'locale' => \$request->input('locale', '')",
    "'title' => \$request->input('title', '')",
    "'excerpt' => \$request->input('excerpt', '')",
    "'status' => \$request->input('status', 'draft')",
    "'body_html' => \$request->input('body_html', '')",
    "'mermaid_validation_digest' => \$request->input('mermaid_validation_digest', '')",
] as $required) {
    requireSnippet($admin, $required, 'admin knowledgebase snippet');
}

$previewDocumentPath = __DIR__ . '/../src/Support/KnowledgebasePreviewDocument.php';
if (!is_file($previewDocumentPath)) {
    fail('knowledgebase preview document renderer is missing');
}
$previewDocument = file_get_contents($previewDocumentPath);
if (!is_string($previewDocument) || trim($previewDocument) === '') {
    fail('knowledgebase preview document renderer is empty');
}
foreach ([
    'Preview — not published',
    '预览 — 尚未发布',
    '預覽 — 尚未發佈',
    '/assets/knowledgebase-docs.css?v=20260914a',
    '/assets/knowledgebase-mermaid.js?v=20260914a',
    '/assets/vendor/mermaid/mermaid.min.js?v=11.12.0',
    'class="kb-preview-page"',
    'class="kb-article-body"',
    'ENT_QUOTES | ENT_SUBSTITUTE',
] as $requiredPreviewSnippet) {
    requireSnippet($previewDocument, $requiredPreviewSnippet, 'knowledgebase preview document snippet');
}
foreach (['cdn.jsdelivr.net', 'unpkg.com', 'http://'] as $forbiddenPreviewSnippet) {
    if (str_contains($previewDocument, $forbiddenPreviewSnippet)) {
        fail('preview document must stay offline and relative: ' . $forbiddenPreviewSnippet);
    }
}

foreach ([
    "\$this->router->add('GET', '/admin/knowledgebase', [\$admin, 'knowledgebaseTopicsPage']);",
    "\$this->router->add('GET', '/admin/knowledgebase/topics/new', [\$admin, 'knowledgebaseTopicEditorPage']);",
    "\$this->router->add('GET', '/admin/knowledgebase/topics/{id}/edit', [\$admin, 'knowledgebaseTopicEditorPage']);",
    "\$this->router->add('POST', '/admin/knowledgebase/topics/save', [\$admin, 'saveKnowledgebaseTopic']);",
    "\$this->router->add('POST', '/admin/knowledgebase/topics/{id}/delete', [\$admin, 'deleteKnowledgebaseTopic']);",
    "\$this->router->add('GET', '/admin/knowledgebase/topics/{id}/articles/{locale}/edit', [\$admin, 'knowledgebaseArticleEditorPage']);",
    "\$this->router->add('POST', '/admin/knowledgebase/articles/save', [\$admin, 'saveKnowledgebaseArticle']);",
    "\$this->router->add('POST', '/admin/knowledgebase/articles/preview', [\$admin, 'previewKnowledgebaseArticle']);",
    "\$this->router->add('POST', '/admin/knowledgebase/articles/{id}/delete', [\$admin, 'deleteKnowledgebaseArticle']);",
    "\$this->router->add('POST', '/admin/knowledgebase/images', [\$admin, 'uploadKnowledgebaseImage']);",
] as $requiredRoute) {
    requireSnippet($bootstrap, $requiredRoute, 'admin knowledgebase route');
}

foreach ([
    "\$this->router->add('GET', '/admin/knowledgebase/articles/preview'",
    "\$this->router->add('GET', '/admin/knowledgebase/preview'",
] as $forbiddenRoute) {
    if (str_contains($bootstrap, $forbiddenRoute)) {
        fail('preview must not have a GET route: ' . $forbiddenRoute);
    }
}

foreach (['Create Thread', 'Manage Threads', 'Save Thread'] as $oldLabel) {
    if (str_contains($admin, $oldLabel)) {
        fail('old combined thread label remains in AdminController: ' . $oldLabel);
    }
}
if (preg_match('~https?://[^\"\']*mermaid~i', $admin . "\n" . $javascript) === 1) {
    fail('Mermaid CDN reference is forbidden in the admin editor');
}

$previewStart = strpos($admin, 'public function previewKnowledgebaseArticle(');
$previewEnd = $previewStart === false ? false : strpos($admin, 'public function ', $previewStart + 20);
if ($previewStart === false || $previewEnd === false) {
    fail('unable to isolate previewKnowledgebaseArticle method');
}
$previewMethod = substr($admin, $previewStart, $previewEnd - $previewStart);
foreach (['saveTopic(', 'saveArticle(', 'deleteTopic(', 'deleteArticle(', 'knowledgebase_threads'] as $writeSnippet) {
    if (str_contains($previewMethod, $writeSnippet)) {
        fail('preview method must not write: ' . $writeSnippet);
    }
}

foreach ([
    'tinymce.triggerSave()',
    'event.submitter',
    "getAttribute('formaction')",
    '/admin/knowledgebase/articles/preview',
    "statusField.value !== 'published'",
    "document.createElement('div')",
    "querySelectorAll('pre')",
    "replace(/\\s+/gu, ' ')",
    "pre.textContent",
    'await window.mermaid.parse(source)',
    'replace(/\\r\\n?/g, \'\\n\')',
    'sources.join("\\n---\\n")',
    "crypto.subtle.digest('SHA-256'",
    "padStart(2, '0')",
    'dataset.mermaidValidated',
    'dataset.mermaidValidationPending',
    'form.requestSubmit(submitter)',
    '`Diagram ${index + 1}: ${message}`',
] as $requiredJavascript) {
    requireSnippet($javascript, $requiredJavascript, 'knowledgebase admin JavaScript snippet');
}

$uploadStart = strpos($admin, 'public function uploadKnowledgebaseImage(');
if ($uploadStart === false) {
    fail('image upload method was removed');
}
$uploadMethod = substr($admin, $uploadStart, 1800);
foreach (['readKnowledgebaseImageUpload()', "move_uploaded_file(", "Response::json(200, ['location'", "SessionAuth::validateCsrf(\$request)"] as $uploadSnippet) {
    requireSnippet($uploadMethod, $uploadSnippet, 'preserved image upload behavior');
}

if (str_contains($admin, 'knowledgebase_threads')) {
    fail('topic-oriented admin controller must not access knowledgebase_threads');
}

runAdminBehaviorTest(__DIR__ . '/../public/assets/knowledgebase-admin.js');

echo "ok\n";
