'use strict';

(function () {
  const form = document.querySelector('[data-kb-article-editor]');
  if (!form) {
    return;
  }

  const errorBox = form.querySelector('[data-kb-mermaid-error]');
  const statusField = form.querySelector('[name="status"]');
  const bodyField = form.querySelector('[name="body_html"]');
  const digestField = form.querySelector('[name="mermaid_validation_digest"]');

  function showError(message) {
    if (!errorBox) {
      return;
    }
    errorBox.textContent = message;
    errorBox.hidden = false;
  }

  function clearError() {
    if (!errorBox) {
      return;
    }
    errorBox.textContent = '';
    errorBox.hidden = true;
  }

  function messageFrom(error) {
    if (error && typeof error.message === 'string' && error.message.trim() !== '') {
      return error.message.trim();
    }
    return String(error || 'Invalid diagram');
  }

  form.addEventListener('submit', async (event) => {
    if (typeof tinymce !== 'undefined' && typeof tinymce.triggerSave === 'function') {
      tinymce.triggerSave();
    }

    const submitter = event.submitter;
    const action = submitter && typeof submitter.getAttribute === 'function'
      ? submitter.getAttribute('formaction')
      : null;
    if (action === '/admin/knowledgebase/articles/preview') {
      return;
    }
    if (!statusField || statusField.value !== 'published') {
      return;
    }
    if (form.dataset.mermaidValidated === 'true') {
      delete form.dataset.mermaidValidated;
      return;
    }

    event.preventDefault();
    clearError();

    const detached = document.createElement('div');
    detached.innerHTML = bodyField ? bodyField.value : '';
    const sources = [];
    const diagrams = Array.from(detached.querySelectorAll('pre')).filter((pre) => {
      const normalizedClassName = String(pre.className || '').replace(/\s+/gu, ' ').trim();
      return normalizedClassName === 'mermaid';
    });

    for (let index = 0; index < diagrams.length; index += 1) {
      const pre = diagrams[index];
      const source = String(pre.textContent || '').replace(/\r\n?/g, '\n').trim();
      try {
        if (!window.mermaid || typeof window.mermaid.parse !== 'function') {
          throw new Error('Diagram validator is unavailable');
        }
        await window.mermaid.parse(source);
        sources.push(source);
      } catch (error) {
        const message = messageFrom(error);
        showError(`Diagram ${index + 1}: ${message}`);
        return;
      }
    }

    const bytes = new TextEncoder().encode(sources.join("\n---\n"));
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    const hex = Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
    if (digestField) {
      digestField.value = hex;
    }
    form.dataset.mermaidValidated = 'true';
    form.requestSubmit(submitter);
  });
}());
