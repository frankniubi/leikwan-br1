'use strict';

const fs = require('fs');
const vm = require('vm');
const { webcrypto } = require('crypto');

const scriptPath = process.argv[2];
if (!scriptPath) {
  throw new Error('knowledgebase-admin.js path is required');
}
const productionScript = fs.readFileSync(scriptPath, 'utf8');

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function same(actual, expected, message) {
  const actualJson = JSON.stringify(actual);
  const expectedJson = JSON.stringify(expected);
  assert(actualJson === expectedJson, `${message}: expected ${expectedJson}, got ${actualJson}`);
}

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
}

async function settle() {
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
}

function decodeHtml(value) {
  return String(value)
    .replace(/&NewLine;/gi, '\n')
    .replace(/&#(?:x0*D|0*13);/gi, '\r')
    .replace(/&#(?:x0*A|0*10);/gi, '\n')
    .replace(/&gt;/gi, '>')
    .replace(/&lt;/gi, '<')
    .replace(/&quot;/gi, '"')
    .replace(/&#(?:x27|39);/gi, "'")
    .replace(/&amp;/gi, '&');
}

function parsePreElements(html) {
  const elements = [];
  const pattern = /<pre\b([^>]*)>([\s\S]*?)<\/pre\s*>/gi;
  let match;
  while ((match = pattern.exec(html)) !== null) {
    const classMatch = /\bclass\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i.exec(match[1]);
    elements.push({
      className: classMatch ? decodeHtml(classMatch[1] ?? classMatch[2] ?? classMatch[3] ?? '') : '',
      textContent: decodeHtml(match[2].replace(/<[^>]*>/g, '')),
    });
  }
  return elements;
}

function makeSubmitter(action = '/admin/knowledgebase/articles/save') {
  return {
    getAttribute(name) {
      return name === 'formaction' ? action : null;
    },
  };
}

function createHarness(options = {}) {
  const statusField = { value: options.status || 'published' };
  const bodyField = { value: options.bodyHtml || '' };
  const digestField = { value: '' };
  const errorBox = { textContent: '', hidden: true };
  const dataset = {};
  const listeners = {};
  const requestSubmissions = [];
  const nativeSubmissions = [];
  const parseSources = [];
  const triggerSaveCalls = [];
  const digestInputs = [];

  const form = {
    dataset,
    querySelector(selector) {
      return {
        '[data-kb-mermaid-error]': errorBox,
        '[name="status"]': statusField,
        '[name="body_html"]': bodyField,
        '[name="mermaid_validation_digest"]': digestField,
      }[selector] || null;
    },
    addEventListener(type, callback) {
      listeners[type] = callback;
    },
    requestSubmit(submitter) {
      requestSubmissions.push(submitter);
      const { event, completion } = dispatchSubmit(submitter);
      Promise.resolve(completion).then(() => {
        if (!event.defaultPrevented) {
          nativeSubmissions.push(submitter);
        }
      });
    },
  };

  const document = {
    querySelector(selector) {
      return selector === '[data-kb-article-editor]' ? form : null;
    },
    createElement(tagName) {
      assert(tagName === 'div', `unexpected detached element: ${tagName}`);
      return {
        innerHTML: '',
        querySelectorAll(selector) {
          assert(selector === 'pre', `unexpected detached selector: ${selector}`);
          return parsePreElements(this.innerHTML);
        },
      };
    },
  };

  const mermaid = {
    parse(source) {
      parseSources.push(source);
      if (typeof options.parse === 'function') {
        return options.parse(source, parseSources.length - 1);
      }
      return Promise.resolve(true);
    },
  };

  const cryptoStub = {
    subtle: {
      async digest(algorithm, bytes) {
        same(algorithm, 'SHA-256', 'production script must request SHA-256');
        digestInputs.push(Buffer.from(bytes).toString('utf8'));
        if (typeof options.digest === 'function') {
          return options.digest(algorithm, bytes);
        }
        return webcrypto.subtle.digest(algorithm, bytes);
      },
    },
  };

  const context = {
    document,
    window: { mermaid },
    tinymce: {
      triggerSave() {
        triggerSaveCalls.push(true);
        if (typeof options.onTriggerSave === 'function') {
          options.onTriggerSave(bodyField);
        }
      },
    },
    crypto: cryptoStub,
    TextEncoder,
    Uint8Array,
    Array,
    String,
    Error,
    console,
  };
  vm.runInNewContext(productionScript, context, { filename: scriptPath });
  assert(typeof listeners.submit === 'function', 'production script must install a submit listener');

  function dispatchSubmit(submitter = makeSubmitter()) {
    const event = {
      submitter,
      defaultPrevented: false,
      preventDefault() {
        this.defaultPrevented = true;
      },
    };
    const completion = listeners.submit(event);
    return { event, completion };
  }

  return {
    statusField,
    bodyField,
    digestField,
    errorBox,
    form,
    requestSubmissions,
    nativeSubmissions,
    parseSources,
    triggerSaveCalls,
    digestInputs,
    dispatchSubmit,
  };
}

async function testPreviewBypass() {
  const harness = createHarness({
    bodyHtml: '<pre class="mermaid">INVALID PREVIEW</pre>',
    parse() {
      throw new Error('preview must not parse');
    },
  });
  const preview = makeSubmitter('/admin/knowledgebase/articles/preview');
  const { event, completion } = harness.dispatchSubmit(preview);
  await completion;

  same(event.defaultPrevented, false, 'preview must bypass publish validation');
  same(harness.parseSources, [], 'preview must not parse Mermaid');
  same(harness.digestInputs, [], 'preview must not compute a digest');
  same(harness.requestSubmissions, [], 'preview must not be resubmitted by JavaScript');
  same(harness.triggerSaveCalls.length, 1, 'preview must save TinyMCE content before native submission');
}

async function testDraftBypass() {
  const harness = createHarness({
    status: 'draft',
    bodyHtml: '<pre class="mermaid">INVALID DRAFT</pre>',
    parse() {
      throw new Error('draft must not parse');
    },
  });
  const { event, completion } = harness.dispatchSubmit();
  await completion;

  same(event.defaultPrevented, false, 'draft must use normal submission');
  same(harness.parseSources, [], 'draft must not parse Mermaid');
  same(harness.digestInputs, [], 'draft must not compute a digest');
  same(harness.requestSubmissions, [], 'draft must not be resubmitted by JavaScript');
  same(harness.triggerSaveCalls.length, 1, 'draft must save TinyMCE content before native submission');
}

async function testNormalizedDigestAndExactClass() {
  const first = 'flowchart TD\r\nA[Fish & Chips]--&gt;B\r';
  const second = '\rsequenceDiagram\r\nAlice-&gt;&gt;Bob: Hi & bye\r\n';
  const normalizedSources = [
    'flowchart TD\nA[Fish & Chips]-->B',
    'sequenceDiagram\nAlice->>Bob: Hi & bye',
  ];
  const harness = createHarness({
    bodyHtml: [
      `<pre class="  mermaid  ">${first}</pre>`,
      '<pre class="mermaid extra">SHOULD NOT PARSE</pre>',
      `<pre class="mermaid">${second}</pre>`,
    ].join(''),
  });
  const submitter = makeSubmitter();
  const { event, completion } = harness.dispatchSubmit(submitter);
  await completion;
  await settle();

  const joined = normalizedSources.join('\n---\n');
  const expectedDigest = '1c6177be2f2896444f3b502a7b1442470f85c7ab39ead51cc9daba7349b9d201';
  same(event.defaultPrevented, true, 'publish must pause for validation');
  same(harness.parseSources, normalizedSources, 'production script must parse normalized entity-decoded exact-class sources');
  same(harness.digestInputs, [joined], 'digest input must match the server source contract');
  same(harness.digestField.value, expectedDigest, 'digest must be lowercase SHA-256 hex');
  assert(/^[0-9a-f]{64}$/.test(harness.digestField.value), 'digest must contain only lowercase hexadecimal');
  same(harness.requestSubmissions, [submitter], 'valid publish must request one final submission');
  same(harness.nativeSubmissions, [submitter], 'guarded resubmission must reach the browser exactly once');
  same(harness.triggerSaveCalls.length, 2, 'initial and guarded final submissions must each sync TinyMCE');
  assert(!('mermaidValidated' in harness.form.dataset), 'guard must be consumed by the final submission');
  assert(!('mermaidValidationPending' in harness.form.dataset), 'pending state must clear before final submission');
}

async function testConcurrentPublishSuppression() {
  const digestGate = deferred();
  const harness = createHarness({
    bodyHtml: '<pre class="mermaid">flowchart TD\nA--&gt;B</pre>',
    digest() {
      return digestGate.promise;
    },
  });
  const first = harness.dispatchSubmit();
  await settle();
  same(harness.digestInputs.length, 1, 'first publish must reach deferred digest validation');
  const duplicate = harness.dispatchSubmit();

  same(first.event.defaultPrevented, true, 'first publish must pause for validation');
  same(duplicate.event.defaultPrevented, true, 'concurrent publish must be suppressed');
  same(harness.parseSources.length, 1, 'concurrent publish must not start another parse');
  same(harness.digestInputs.length, 1, 'concurrent publish must not start another digest');
  same(harness.requestSubmissions.length, 0, 'no submission may occur while validation is pending');

  digestGate.resolve(await webcrypto.subtle.digest('SHA-256', new TextEncoder().encode(harness.digestInputs[0])));
  await first.completion;
  await duplicate.completion;
  await settle();
  same(harness.requestSubmissions.length, 1, 'pending validation must produce exactly one final submission');
}

async function testParseFailureRecoveryAndRetry() {
  let shouldFail = true;
  const harness = createHarness({
    bodyHtml: '<pre class="mermaid">flowchart TD\nA--&gt;B</pre>',
    parse() {
      if (shouldFail) {
        return Promise.reject(new Error('  broken diagram  '));
      }
      return Promise.resolve(true);
    },
  });

  const failed = harness.dispatchSubmit();
  await failed.completion;
  same(failed.event.defaultPrevented, true, 'invalid publish must be blocked');
  same(harness.requestSubmissions.length, 0, 'invalid publish must not submit');
  same(harness.digestInputs.length, 0, 'invalid publish must not compute a digest');
  same(harness.errorBox.textContent, 'Diagram 1: broken diagram', 'parse failure must show a trimmed indexed error');
  same(harness.errorBox.hidden, false, 'parse failure must reveal the error');
  assert(!('mermaidValidationPending' in harness.form.dataset), 'parse failure must clear pending state');

  shouldFail = false;
  const retry = harness.dispatchSubmit();
  await retry.completion;
  await settle();
  same(retry.event.defaultPrevented, true, 'retry must validate before submission');
  same(harness.parseSources.length, 2, 'retry must parse again after failure');
  same(harness.requestSubmissions.length, 1, 'successful retry must submit exactly once');
  same(harness.errorBox.textContent, '', 'retry must clear the old parse error');
  same(harness.errorBox.hidden, true, 'retry must hide the old parse error');
}

(async () => {
  await testPreviewBypass();
  await testDraftBypass();
  await testNormalizedDigestAndExactClass();
  await testConcurrentPublishSuppression();
  await testParseFailureRecoveryAndRetry();
  process.stdout.write('ok\n');
})().catch((error) => {
  console.error(error && error.stack ? error.stack : error);
  process.exit(1);
});
