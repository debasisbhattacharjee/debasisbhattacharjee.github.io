(function () {
  "use strict";

  const hasHost = !!(window.chrome && window.chrome.webview);

  const state = {
    issues: [],
    systemInfo: null,
    activeCategory: "all",
    activeView: "dashboard",
    theme: "dark"
  };

  function post(action, extra) {
    const message = Object.assign({ action }, extra || {});
    if (hasHost) {
      window.chrome.webview.postMessage(JSON.stringify(message));
    } else {
      console.log("[preview] would send to host:", message);
    }
  }

  function el(sel) { return document.querySelector(sel); }
  function all(sel) { return Array.from(document.querySelectorAll(sel)); }

  // ---------------- Navigation ----------------
  all(".nav-item").forEach(btn => {
    btn.addEventListener("click", () => {
      all(".nav-item").forEach(b => b.classList.remove("active"));
      btn.classList.add("active");
      const view = btn.dataset.view;
      showView(view);
    });
  });

  function showView(view) {
    state.activeView = view;
    const dashboardParts = ["#view-summary", ".body-grid"];
    const isDashboardish = ["dashboard", "all", "DllFile", "Runtime", "DirectX", "System"].includes(view);

    el("#view-backup").classList.toggle("hidden", view !== "backup");
    el("#view-settings").classList.toggle("hidden", view !== "settings");
    dashboardParts.forEach(sel => el(sel).classList.toggle("hidden", !isDashboardish));

    if (view === "manual") {
      post("openManualFolder");
      // fall back to dashboard view visually
      el("#view-backup").classList.add("hidden");
      el("#view-settings").classList.add("hidden");
      dashboardParts.forEach(sel => el(sel).classList.remove("hidden"));
      return;
    }

    if (isDashboardish) {
      const cat = view === "dashboard" ? "all" : view;
      setActiveTab(cat);
    }
  }

  // ---------------- Tabs ----------------
  all(".tab").forEach(tab => {
    tab.addEventListener("click", () => setActiveTab(tab.dataset.cat));
  });

  function setActiveTab(cat) {
    state.activeCategory = cat;
    all(".tab").forEach(t => t.classList.toggle("active", t.dataset.cat === cat));
    renderIssues();
  }

  // ---------------- Buttons ----------------
  el("#rescanBtn").addEventListener("click", () => {
    el("#heroTitle").textContent = "Rescanning your PC…";
    post("scan");
  });

  el("#repairAllBtn").addEventListener("click", () => {
    const ids = state.issues
      .filter(i => matchesCategory(i, state.activeCategory))
      .map(i => i.id);
    if (ids.length === 0) return;
    el("#repairAllBtn").disabled = true;
    el("#repairAllBtn").textContent = "Repairing…";
    post("repairAll", { ids });
  });

  el("#checkAll").addEventListener("change", e => {
    all(".row-check").forEach(cb => cb.checked = e.target.checked);
  });

  el("#createRestoreBtn").addEventListener("click", () => post("createRestorePoint"));
  el("#openProtectionBtn").addEventListener("click", () => post("openSystemProtection"));

  all(".theme-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      applyTheme(btn.dataset.theme);
      post("setTheme", { theme: btn.dataset.theme });
    });
  });

  function applyTheme(theme) {
    state.theme = theme;
    document.documentElement.setAttribute("data-theme", theme);
    all(".theme-btn").forEach(b => b.classList.toggle("active", b.dataset.theme === theme));
  }

  // ---------------- Rendering ----------------
  function matchesCategory(issue, cat) {
    return cat === "all" || issue.category === cat;
  }

  function severityRank(sev) {
    return { High: 0, Medium: 1, Low: 2, Info: 3 }[sev] ?? 4;
  }

  function renderIssues() {
    const body = el("#issuesBody");
    const filtered = state.issues
      .filter(i => matchesCategory(i, state.activeCategory))
      .sort((a, b) => severityRank(a.severity) - severityRank(b.severity));

    if (filtered.length === 0) {
      body.innerHTML = `<tr class="empty-row"><td colspan="6">No issues found in this category. Nice and clean.</td></tr>`;
    } else {
      body.innerHTML = filtered.map(rowHtml).join("");
      all(".row-fix-btn").forEach(btn => {
        btn.addEventListener("click", () => {
          btn.disabled = true;
          btn.textContent = "Working…";
          post("fix", { id: btn.dataset.id });
        });
      });
    }

    updateTabCounts();
  }

  function rowHtml(issue) {
    const statusClass = issue.status === "Fixed" ? "fixed" : (issue.status === "Missing" ? "" : "working");
    return `
      <tr data-id="${issue.id}">
        <td><input type="checkbox" class="row-check" checked /></td>
        <td>
          <div class="issue-name">${escapeHtml(issue.name)}</div>
          <div class="issue-detail">${escapeHtml(issue.details || "")}</div>
        </td>
        <td>${categoryLabel(issue.category)}</td>
        <td><span class="badge badge-${issue.severity}">${issue.severity}</span></td>
        <td><span class="status-pill ${statusClass}" data-role="status">${escapeHtml(issue.status)}</span></td>
        <td class="col-action"><button class="row-fix-btn" data-id="${issue.id}">Fix</button></td>
      </tr>`;
  }

  function categoryLabel(cat) {
    return {
      DllFile: "DLL File",
      Runtime: "Runtime",
      DirectX: "DirectX",
      System: "System",
      Application: "Application"
    }[cat] || cat;
  }

  function updateTabCounts() {
    const counts = { all: state.issues.length };
    state.issues.forEach(i => { counts[i.category] = (counts[i.category] || 0) + 1; });
    all("[data-count-for]").forEach(span => {
      const key = span.dataset.countFor;
      span.textContent = counts[key] || 0;
    });
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  }

  function renderSystemInfo(info) {
    const list = el("#systemInfoList");
    if (!info) return;
    list.innerHTML = `
      <li><span>OS</span><b>${escapeHtml(info.osName)} (${escapeHtml(info.osArchitecture)})</b></li>
      <li><span>CPU</span><b>${escapeHtml(info.cpu)}</b></li>
      <li><span>GPU</span><b>${escapeHtml(info.gpu)}</b></li>
      <li><span>RAM</span><b>${info.totalRamGb} GB</b></li>
      <li><span>Drive</span><b>${escapeHtml(info.primaryDrive)}</b></li>
    `;
  }

  function updateHero() {
    const total = state.issues.length;
    const highCount = state.issues.filter(i => i.severity === "High").length;
    el("#heroTitle").textContent = total === 0
      ? "Your PC looks healthy"
      : `Scan complete - ${total} item${total === 1 ? "" : "s"} found`;
    el("#heroSubtitle").textContent = total === 0
      ? "No missing DLLs, runtimes or DirectX components were detected on this scan."
      : `${highCount} high priority item${highCount === 1 ? "" : "s"}. Review below, or repair everything at once.`;

    el("#statHealth").textContent = highCount > 0 ? "Needs attention" : (total > 0 ? "Good" : "Excellent");
    el("#statPerf").textContent = total > 0 ? `${total} to optimize` : "Optimized";
    el("#statStability").textContent = highCount > 0 ? "At risk" : "Stable";
    el("#statGame").textContent = state.issues.some(i => i.category === "DirectX") ? "Needs repair" : "Ready";

    el("#repairAllBtn").disabled = total === 0;
  }

  // ---------------- Host bridge ----------------
  window.sysfix = {
    onScanResult(result) {
      state.issues = (result.issues || []).map(i => ({
        id: i.id, name: i.name, details: i.details, category: i.category,
        severity: i.severity, status: i.status
      }));
      state.systemInfo = result.systemInfo;
      renderIssues();
      renderSystemInfo(result.systemInfo);
      updateHero();
      el("#repairAllBtn").disabled = state.issues.length === 0;
      el("#repairAllBtn").textContent = "Repair All Issues";
    },

    onFixResult(result) {
      const row = document.querySelector(`tr[data-id="${result.id}"]`);
      if (row) {
        const statusEl = row.querySelector('[data-role="status"]');
        const btn = row.querySelector(".row-fix-btn");
        if (statusEl) {
          statusEl.textContent = result.success ? "Action opened" : "Failed";
          statusEl.className = "status-pill " + (result.success ? "fixed" : "");
        }
        if (btn) {
          btn.disabled = false;
          btn.textContent = "Fix";
        }
      }
      el("#repairAllBtn").disabled = false;
      el("#repairAllBtn").textContent = "Repair All Issues";
    },

    onBackupResult(result) {
      const box = el("#backupResult");
      box.textContent = result.message;
      box.className = "result-msg " + (result.success ? "success" : "error");
    },

    onSettings(settings) {
      applyTheme(settings.theme || "dark");
    }
  };

  // ---------------- Boot ----------------
  if (!hasHost) {
    el("#previewBanner").classList.remove("hidden");
    applyTheme("dark");
    updateHero();
  }

  post("ready");
})();
