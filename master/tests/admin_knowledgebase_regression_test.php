<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');

if (!is_string($admin) || $admin === '') {
    fail('failed to read AdminController source');
}
if (!is_string($bootstrap) || $bootstrap === '') {
    fail('failed to read Bootstrap source');
}

foreach ([
    'public function knowledgebaseThreadsPage(Request $request, array $params): Response',
    'public function knowledgebaseEditorPage(Request $request, array $params): Response',
    'public function saveKnowledgebaseThread(Request $request, array $params): Response',
    'public function uploadKnowledgebaseImage(Request $request, array $params): Response',
    'tinymce.min.js',
    'paste_data_images: true',
    '/admin/knowledgebase/images',
    'new FormData()',
    'Paste images directly into the editor.',
    'multipart/form-data',
] as $required) {
    if (!str_contains($admin, $required)) {
        fail('missing admin knowledgebase snippet: ' . $required);
    }
}

foreach ([
    "\$this->router->add('GET', '/admin/knowledgebase', [\$admin, 'knowledgebaseThreadsPage']);",
    "\$this->router->add('GET', '/admin/knowledgebase/new', [\$admin, 'knowledgebaseEditorPage']);",
    "\$this->router->add('GET', '/admin/knowledgebase/{id}/edit', [\$admin, 'knowledgebaseEditorPage']);",
    "\$this->router->add('POST', '/admin/knowledgebase/save', [\$admin, 'saveKnowledgebaseThread']);",
    "\$this->router->add('POST', '/admin/knowledgebase/images', [\$admin, 'uploadKnowledgebaseImage']);",
] as $requiredRoute) {
    if (!str_contains($bootstrap, $requiredRoute)) {
        fail('missing admin knowledgebase route: ' . $requiredRoute);
    }
}

echo "ok\n";
