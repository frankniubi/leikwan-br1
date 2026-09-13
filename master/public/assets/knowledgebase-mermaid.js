(function () {
  async function renderDiagrams() {
    const sources = Array.from(document.querySelectorAll("pre.mermaid"));
    if (!sources.length || !window.mermaid) return;

    window.mermaid.initialize({
      startOnLoad: false,
      securityLevel: "strict",
      htmlLabels: false,
      theme: "base",
      fontFamily: "Inter, \"Segoe UI\", \"PingFang SC\", \"Microsoft YaHei\", sans-serif",
      themeVariables: {
        fontSize: "13px",
        primaryColor: "#f6f9f7",
        primaryBorderColor: "#c3d3c9",
        primaryTextColor: "#15221c",
        lineColor: "#8ba396",
        secondaryColor: "#eaf3ee",
        tertiaryColor: "#ffffff",
        edgeLabelBackground: "#ffffff",
        clusterBkg: "#f6f9f7",
        clusterBorder: "#dfe7e1"
      },
      flowchart: {
        useMaxWidth: false,
        nodeSpacing: 28,
        rankSpacing: 36,
        padding: 8,
        diagramPadding: 4
      }
    });

    for (let index = 0; index < sources.length; index += 1) {
      const source = sources[index];
      const definition = source.textContent || "";
      try {
        const result = await window.mermaid.render(`kb-mermaid-${index}`, definition);
        const figure = document.createElement("figure");
        figure.className = "kb-mermaid-figure";
        figure.innerHTML = result.svg;
        const svg = figure.querySelector("svg");
        if (svg) {
          let title = svg.querySelector("title[id]");
          if (!title) {
            title = document.createElementNS("http://www.w3.org/2000/svg", "title");
            title.id = `kb-mermaid-title-${index}`;
            title.textContent = source.dataset.diagramLabel || "Guide diagram";
            svg.prepend(title);
          }
          const description = svg.querySelector("desc[id]");
          const labelledBy = [title.id];
          if (description) labelledBy.push(description.id);
          svg.setAttribute("role", "img");
          svg.setAttribute("aria-labelledby", labelledBy.join(" "));
          svg.removeAttribute("height");
          svg.style.maxWidth = "100%";
          svg.style.height = "auto";
        }
        source.replaceWith(figure);
        if (typeof result.bindFunctions === "function") result.bindFunctions(figure);
      } catch (error) {
        source.classList.add("kb-mermaid-failed");
        const notice = document.createElement("p");
        notice.className = "kb-mermaid-error";
        notice.textContent = source.dataset.errorLabel
          || source.getAttribute("data-error-label")
          || "Diagram could not be rendered. The source is shown below.";
        source.before(notice);
      }
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", renderDiagrams);
  } else {
    renderDiagrams();
  }
}());
