#!/usr/bin/env python3
"""
بررسی نحوی (Syntax Check) فایل‌های C# با پارسر رسمی tree-sitter.

چرا این ابزار؟ در محیط‌هایی که SDK دات‌نت نصب نیست (یا پیش از اجرای build)،
این اسکریپت خطاهای نگارشی C# را پیش از کامیت پیدا می‌کند.

نصب پیش‌نیاز (یک‌بار):
    pip install tree-sitter tree-sitter-c-sharp

اجرا:
    python3 tools/check-cs/check_cs.py            # بررسی src و tests
    python3 tools/check-cs/check_cs.py src tests  # مسیرهای دلخواه

خروجی: کد خروج ۰ یعنی هیچ خطای نگارشی یافت نشد.
"""

from __future__ import annotations

import pathlib
import sys

try:
    import tree_sitter_c_sharp
    from tree_sitter import Language, Parser
except ImportError:  # pragma: no cover
    print("پیش‌نیاز نصب نیست. اجرا کنید:  pip install tree-sitter tree-sitter-c-sharp")
    sys.exit(2)

ERROR_NODE = "ERROR"


def build_parser() -> "Parser":
    language = Language(tree_sitter_c_sharp.language())
    try:
        return Parser(language)
    except TypeError:  # نسخه‌های قدیمی‌تر API
        parser = Parser()
        parser.set_language(language)
        return parser


def collect_problems(node, problems: list[tuple[int, int, str]]) -> None:
    if node.type == ERROR_NODE or node.is_missing:
        label = "خطای نگارشی" if node.type == ERROR_NODE else f"ناقص: {node.type}"
        snippet = node.text.decode("utf-8", errors="replace").strip().splitlines()
        excerpt = snippet[0][:80] if snippet else ""
        problems.append((node.start_point[0] + 1, node.start_point[1] + 1, f"{label} → {excerpt}"))

    for child in node.children:
        collect_problems(child, problems)


def check_file(parser: "Parser", path: pathlib.Path) -> list[tuple[int, int, str]]:
    source = path.read_bytes()
    tree = parser.parse(source)
    problems: list[tuple[int, int, str]] = []
    collect_problems(tree.root_node, problems)
    return problems


def main(argv: list[str]) -> int:
    targets = argv[1:] or ["src", "tests"]
    parser = build_parser()

    files: list[pathlib.Path] = []
    for target in targets:
        path = pathlib.Path(target)
        if path.is_file() and path.suffix == ".cs":
            files.append(path)
        elif path.is_dir():
            files.extend(sorted(p for p in path.rglob("*.cs") if "obj" not in p.parts and "bin" not in p.parts))

    if not files:
        print("هیچ فایل C# یافت نشد.")
        return 1

    total_problems = 0
    for file in files:
        problems = check_file(parser, file)
        if problems:
            print(f"\n{file}")
            for line, column, message in problems[:20]:
                print(f"  خط {line}:{column}  {message}")
            if len(problems) > 20:
                print(f"  … و {len(problems) - 20} مورد دیگر")
            total_problems += len(problems)

    print(f"\nبررسی‌شده: {len(files)} فایل | خطای نگارشی: {total_problems}")
    return 0 if total_problems == 0 else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
