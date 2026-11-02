#!/usr/bin/env python3
"""Ensure every PHP test in Feature and Unit belongs to one CI suite."""

from collections import Counter
from pathlib import Path
import sys
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parents[2]
CONFIG = ROOT / "phpunit.xml"


def covered_files() -> Counter[Path]:
    suites = ET.parse(CONFIG).getroot().find("testsuites")
    if suites is None:
        raise ValueError("phpunit.xml has no testsuites")

    covered: Counter[Path] = Counter()
    for suite in suites.findall("testsuite"):
        for directory in suite.findall("directory"):
            folder = ROOT / (directory.text or "")
            if not folder.is_dir():
                raise ValueError(f"Suite {suite.get('name')} references missing directory: {folder}")
            suffix = directory.get("suffix", "Test.php")
            covered.update(path.resolve() for path in folder.rglob(f"*{suffix}"))
        for file in suite.findall("file"):
            path = ROOT / (file.text or "")
            if not path.is_file():
                raise ValueError(f"Suite {suite.get('name')} references missing file: {path}")
            covered[path.resolve()] += 1
    return covered


def main() -> int:
    try:
        covered = covered_files()
    except (ET.ParseError, ValueError) as error:
        print(error, file=sys.stderr)
        return 1

    expected = {path.resolve() for area in ("Feature", "Unit")
                for path in (ROOT / "tests" / area).rglob("*Test.php")}
    missing = sorted(expected - covered.keys())
    duplicates = sorted(path for path in expected if covered[path] != 1 and path in covered)
    if missing or duplicates:
        for label, paths in (("uncovered", missing), ("multiple suites", duplicates)):
            for path in paths:
                print(f"{label}: {path.relative_to(ROOT)}", file=sys.stderr)
        return 1

    print(f"PHP test suite coverage: {len(expected)} files in Feature/Unit, each covered once")
    return 0


if __name__ == "__main__":
    sys.exit(main())
