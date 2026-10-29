/* Client-side PDF export for an analytics report: every KPI, chart and table is captured as shown. */
(function () {
    'use strict';
    var btn = document.getElementById('anPdf');
    if (!btn) { return; }

    function filterSummary() {
        var form = document.getElementById('anFilters');
        var parts = [];
        if (!form) { return parts; }
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || el.name === 'r' || el.type === 'hidden' || el.type === 'submit' || el.type === 'button' || !el.value) { return; }
            var label = el.closest('label');
            var name = label ? label.childNodes[0].textContent.trim() : el.name;
            var text = el.tagName === 'SELECT' ? el.options[el.selectedIndex].text : el.value;
            if (name && text) { parts.push(name + ': ' + text); }
        });
        return parts;
    }

    function setBusy(on, text) {
        btn.disabled = on;
        btn.querySelector('i').className = on ? 'fas fa-spinner fa-spin' : 'fas fa-file-pdf';
        btn.lastChild.textContent = ' ' + text;
    }

    function run() {
        if (!window.jspdf || !window.html2canvas) { alert('PDF tools failed to load. Refresh the page and try again.'); return; }
        var jsPDF = window.jspdf.jsPDF;
        var doc = new jsPDF({ unit: 'pt', format: 'a4' });
        var W = doc.internal.pageSize.getWidth();
        var H = doc.internal.pageSize.getHeight();
        var M = 32, top = 74, bottom = H - 34, usable = W - M * 2;

        var blocks = Array.prototype.slice.call(document.querySelectorAll('.an-report > .an-section, .an-report > .an-note, .an-report > .an-kpis, .an-report > .an-grid > .an-chart'));
        var title = btn.dataset.title || document.title;
        var meta = filterSummary().join('  |  ');
        var generated = 'Generated ' + new Date().toLocaleString() + (btn.dataset.user ? ' by ' + btn.dataset.user : '');

        function frame(pageNo) {
            doc.setFillColor(30, 64, 175);
            doc.rect(0, 0, W, 6, 'F');
            doc.setTextColor(30, 41, 59);
            doc.setFont('helvetica', 'bold'); doc.setFontSize(15);
            doc.text(title, M, 30);
            doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(100, 116, 139);
            doc.text('Lubaga Hospital MCH - Strategic Analytics', M, 43);
            var lines = doc.splitTextToSize(meta, usable);
            doc.text(lines.slice(0, 2), M, 54);
            doc.setDrawColor(226, 232, 240); doc.line(M, 66, W - M, 66);
            doc.text(generated, M, H - 16);
            doc.text('Page ' + pageNo, W - M, H - 16, { align: 'right' });
        }
        var page = 1;
        frame(page);

        var report = document.querySelector('.an-report');
        setBusy(true, 'Building PDF…');
        document.body.classList.add('an-pdf-mode');
        var rTop = report.getBoundingClientRect().top + window.scrollY;
        var edges = blocks.map(function (el) {
            var r = el.getBoundingClientRect();
            return { top: r.top + window.scrollY - rTop, bottom: r.bottom + window.scrollY - rTop, head: el.classList.contains('an-section') };
        });
        var cuts = [];
        edges.forEach(function (e) {
            [e.top, e.bottom].forEach(function (c) {
                if (e.head && c === e.bottom) { return; }
                var straddled = edges.some(function (o) { return o.top < c - 1 && o.bottom > c + 1; });
                if (!straddled) { cuts.push(c); }
            });
        });
        var scale = Math.min(2, 28000 / Math.max(report.scrollHeight, 1));

        window.html2canvas(report, { scale: scale, backgroundColor: '#ffffff', logging: false, useCORS: true }).then(function (canvas) {
            var ratio = usable / (report.offsetWidth * scale);
            var cap = (bottom - top) / ratio;
            var total = canvas.height;
            var start = 0;
            while (start < total - 1) {
                var end = Math.min(start + cap, total);
                if (end < total) {
                    // cut at the last position no block straddles; never strand a heading at the page foot
                    var best = -1;
                    cuts.forEach(function (c) {
                        var px = c * scale;
                        if (px > start + 40 && px <= end && px > best) { best = px; }
                    });
                    if (best > 0) { end = best; }
                }
                var h = end - start;
                var strip = document.createElement('canvas');
                strip.width = canvas.width; strip.height = Math.ceil(h);
                var ctx = strip.getContext('2d');
                ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, strip.width, strip.height);
                ctx.drawImage(canvas, 0, start, canvas.width, h, 0, 0, canvas.width, h);
                doc.addImage(strip.toDataURL('image/jpeg', 0.92), 'JPEG', M, top, usable, h * ratio);
                start = end;
                if (start < total - 1) { doc.addPage(); page += 1; frame(page); }
            }
            document.body.classList.remove('an-pdf-mode');
            doc.save(btn.dataset.file || 'analytics.pdf');
            setBusy(false, 'Download PDF');
        }).catch(function (err) {
            document.body.classList.remove('an-pdf-mode');
            setBusy(false, 'Download PDF');
            alert('Could not build the PDF: ' + err);
        });
    }

    btn.addEventListener('click', run);
}());
