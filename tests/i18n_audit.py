from __future__ import annotations

import argparse
import json
import re
from dataclasses import dataclass
from pathlib import Path


ROOT_REL = Path("webserver/control_edudisplej_sk")
SCAN_EXTENSIONS = {".php", ".html", ".js"}
EXCLUDE_DIR_NAMES = {
    "logs",
    "uploads",
    "screenshots",
    "lang",
    ".git",
    "node_modules",
    "vendor",
    ".venv",
}

T_CALL_RE = re.compile(r"\bt(?:_def)?\(\s*['\"]([^'\"]+)['\"]")
I18N_PAIR_RE = re.compile(r"['\"]([a-z0-9_.-]{3,})['\"]\s*=>")
JSON_KEY_RE = re.compile(r'"([a-z0-9_.-]{3,})"\s*:')

HTML_TEXT_RE = re.compile(r">([^<>{}]+)<")
QUOTED_TEXT_RE = re.compile(r"['\"]([^'\"\n]{3,})['\"]")


@dataclass
class Finding:
    kind: str
    file: str
    line: int
    text: str


def should_skip_file(path: Path) -> bool:
    if path.suffix.lower() not in SCAN_EXTENSIONS:
        return True
    parts = {part.lower() for part in path.parts}
    return any(excluded in parts for excluded in EXCLUDE_DIR_NAMES)


def normalize_ws(text: str) -> str:
    return re.sub(r"\s+", " ", text).strip()


def looks_human_text(text: str) -> bool:
    text = normalize_ws(text)
    if len(text) < 3:
        return False
    if text.count(" ") == 0 and len(text) < 5:
        return False
    if re.fullmatch(r"[0-9\W_]+", text):
        return False
    if text.startswith(("http://", "https://", "/", "#")):
        return False
    if re.search(r"[{}$\\]|<\?php|\?>", text):
        return False
    if any(mark in text for mark in (" href=", " src=", " class=", " style=", "</", "<")):
        return False
    if '"' in text or "'" in text:
        return False
    if re.fullmatch(r"[a-zA-Z0-9_.:/-]+", text) and " " not in text:
        return False
    if re.search(r"[=:/]", text) and " " not in text:
        return False
    if re.search(r"(SELECT|INSERT|UPDATE|DELETE|ALTER|CREATE TABLE|DROP TABLE)", text, re.IGNORECASE):
        return False
    if re.search(r"(linear-gradient|display:\s*|grid-template|utf8|utf8mb4)", text, re.IGNORECASE):
        return False
    return bool(re.search(r"[A-Za-zÁÉÍÓÖŐÚÜŰáéíóöőúüű]", text))


def load_catalog_keys(base_dir: Path) -> set[str]:
    keys: set[str] = set()
    i18n_php = base_dir / ROOT_REL / "i18n.php"
    if i18n_php.exists():
        content = i18n_php.read_text(encoding="utf-8", errors="ignore")
        keys.update(I18N_PAIR_RE.findall(content))

    lang_dir = base_dir / ROOT_REL / "lang"
    if lang_dir.exists():
        for lang_file in lang_dir.glob("*.json"):
            text = lang_file.read_text(encoding="utf-8", errors="ignore")
            keys.update(JSON_KEY_RE.findall(text))
            try:
                data = json.loads(text)
                if isinstance(data, dict):
                    keys.update(k for k in data.keys() if isinstance(k, str))
            except json.JSONDecodeError:
                continue
    return keys


def collect_project_files(base_dir: Path) -> list[Path]:
    root = base_dir / ROOT_REL
    files: list[Path] = []
    for path in root.rglob("*"):
        if path.is_file() and not should_skip_file(path):
            files.append(path)
    return files


def scan_for_t_keys(files: list[Path], rel_base: Path) -> tuple[set[str], dict[str, list[str]], list[Finding]]:
    used_keys: set[str] = set()
    used_key_locations: dict[str, list[str]] = {}
    findings: list[Finding] = []
    for path in files:
        text = path.read_text(encoding="utf-8", errors="ignore")
        for match in T_CALL_RE.finditer(text):
            key = match.group(1).strip()
            if not key or key.endswith(".") or " " in key:
                continue
            used_keys.add(key)
            line_no = text.count("\n", 0, match.start()) + 1
            location = f"{str(path.relative_to(rel_base)).replace('\\', '/')}:L{line_no}"
            used_key_locations.setdefault(key, []).append(location)

        lines = text.splitlines()
        if path.suffix.lower() == ".php":
            has_include = any("i18n.php" in line for line in lines)
            uses_t = any("t(" in line or "t_def(" in line for line in lines)
            if uses_t and not has_include and path.name != "i18n.php":
                findings.append(
                    Finding(
                        kind="missing_i18n_include",
                        file=str(path.relative_to(rel_base)).replace("\\", "/"),
                        line=1,
                        text="Uses t()/t_def() but i18n.php include not found",
                    )
                )
    return used_keys, used_key_locations, findings


def context_is_ui_line(line: str) -> bool:
    ui_markers = (
        "echo",
        "print",
        "alert(",
        "confirm(",
        "placeholder=",
        "title=",
        "aria-label=",
        "textContent",
        "innerText",
        ".text(",
        "<button",
        "<label",
        "<h1",
        "<h2",
        "<h3",
        "<p",
        "<span",
        "<a ",
        "<th",
        "<td",
    )
    return any(marker in line for marker in ui_markers)


def scan_for_hardcoded_ui_text(files: list[Path], rel_base: Path) -> list[Finding]:
    findings: list[Finding] = []
    for path in files:
        lines = path.read_text(encoding="utf-8", errors="ignore").splitlines()
        for idx, line in enumerate(lines, start=1):
            stripped = line.strip()
            if not stripped:
                continue
            if stripped.startswith(("//", "/*", "*", "#")):
                continue
            if "<?php" in line and "?>" not in line and "<" not in stripped.replace("<?php", ""):
                continue
            if "t(" in line or "t_def(" in line:
                continue

            for m in HTML_TEXT_RE.finditer(line):
                candidate = normalize_ws(m.group(1))
                if looks_human_text(candidate):
                    findings.append(
                        Finding(
                            kind="hardcoded_html_text",
                            file=str(path.relative_to(rel_base)).replace("\\", "/"),
                            line=idx,
                            text=candidate[:220],
                        )
                    )

            if context_is_ui_line(line):
                for m in QUOTED_TEXT_RE.finditer(line):
                    candidate = normalize_ws(m.group(1))
                    if looks_human_text(candidate):
                        findings.append(
                            Finding(
                                kind="hardcoded_ui_literal",
                                file=str(path.relative_to(rel_base)).replace("\\", "/"),
                                line=idx,
                                text=candidate[:220],
                            )
                        )

    unique: dict[tuple[str, str, int, str], Finding] = {}
    for item in findings:
        unique[(item.kind, item.file, item.line, item.text)] = item
    return list(unique.values())


def write_markdown_report(
    output_path: Path,
    missing_keys: list[str],
    used_key_locations: dict[str, list[str]],
    findings: list[Finding],
) -> None:
    by_kind: dict[str, list[Finding]] = {}
    for finding in findings:
        by_kind.setdefault(finding.kind, []).append(finding)

    lines: list[str] = []
    lines.append("# i18n audit report")
    lines.append("")
    lines.append(f"- Missing translation keys referenced by `t(...)`: **{len(missing_keys)}**")
    lines.append(f"- Potential hardcoded user-facing strings: **{len(findings)}**")
    lines.append("")

    if missing_keys:
        lines.append("## Missing keys")
        for key in missing_keys:
            locations = used_key_locations.get(key, [])
            sample = ", ".join(f"`{loc}`" for loc in locations[:3])
            if sample:
                lines.append(f"- `{key}` → {sample}")
            else:
                lines.append(f"- `{key}`")
        lines.append("")

    for kind in sorted(by_kind.keys()):
        group = sorted(by_kind[kind], key=lambda x: (x.file, x.line, x.text))
        lines.append(f"## {kind}")
        for finding in group:
            lines.append(f"- `{finding.file}:{finding.line}` → {finding.text}")
        lines.append("")

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text("\n".join(lines), encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description="Audit i18n coverage in EduDisplej project")
    parser.add_argument(
        "--base-dir",
        default=".",
        help="Repository root directory",
    )
    parser.add_argument(
        "--output",
        default="docs/I18N_AUDIT_REPORT.md",
        help="Output markdown report path (relative to base-dir)",
    )
    args = parser.parse_args()

    base_dir = Path(args.base_dir).resolve()
    files = collect_project_files(base_dir)
    catalog_keys = load_catalog_keys(base_dir)
    used_keys, used_key_locations, include_findings = scan_for_t_keys(files, base_dir)

    missing_keys = sorted(key for key in used_keys if key not in catalog_keys)
    ui_findings = scan_for_hardcoded_ui_text(files, base_dir)

    all_findings = include_findings + ui_findings

    output_path = (base_dir / args.output).resolve()
    write_markdown_report(output_path, missing_keys, used_key_locations, all_findings)

    print(f"Scanned files: {len(files)}")
    print(f"Used translation keys: {len(used_keys)}")
    print(f"Catalog keys: {len(catalog_keys)}")
    print(f"Missing keys: {len(missing_keys)}")
    print(f"Potential hardcoded strings: {len(all_findings)}")
    print(f"Report: {output_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
