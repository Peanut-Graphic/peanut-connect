#!/usr/bin/env python3
"""Read-only validator for Peanut .peanut/platform.yml manifests."""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path
from typing import Any

try:
    import yaml
    from jsonschema import Draft202012Validator
    from jsonschema.exceptions import SchemaError
except ImportError as exc:  # pragma: no cover - exercised by installation failures
    print(
        "platform manifest validator dependencies are missing; run "
        "python3 -m pip install -r requirements-platform-validator.txt",
        file=sys.stderr,
    )
    raise SystemExit(2) from exc


REPO_EXCLUDES = {
    ".git",
    ".worktrees",
    ".Codex",
    "node_modules",
    "vendor",
    "DerivedData",
    "build",
    "dist",
    "release",
}
DEFAULT_SCHEMA = Path(__file__).resolve().parents[1] / "schemas" / "platform" / "v1.json"


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Report validation results for Peanut platform manifests without modifying them."
    )
    parser.add_argument(
        "paths",
        nargs="*",
        type=Path,
        help="Manifest files or directories to search (default: current directory).",
    )
    parser.add_argument(
        "--root",
        action="append",
        default=[],
        type=Path,
        help="Discover Git repositories directly below an ecosystem root; repeatable.",
    )
    parser.add_argument("--schema", type=Path, default=DEFAULT_SCHEMA)
    parser.add_argument(
        "--require-manifest",
        action="store_true",
        help="Treat repositories without .peanut/platform.yml as findings in strict mode.",
    )
    parser.add_argument(
        "--strict",
        action="store_true",
        help="Exit 1 for validation findings. Not intended for blocking CI during rollout.",
    )
    parser.add_argument("--json", action="store_true", dest="json_output")
    return parser.parse_args(argv)


def load_validator(schema_path: Path) -> Draft202012Validator:
    try:
        schema = json.loads(schema_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"cannot load schema {schema_path}: {exc}") from exc

    try:
        Draft202012Validator.check_schema(schema)
    except SchemaError as exc:
        raise RuntimeError(f"invalid Draft 2020-12 schema {schema_path}: {exc.message}") from exc
    return Draft202012Validator(schema)


def discover_repositories(root: Path) -> list[Path]:
    repositories: list[Path] = []
    if not root.is_dir():
        return repositories
    for current, directories, files in os.walk(root):
        current_path = Path(current)
        depth = len(current_path.relative_to(root).parts)
        if ".git" in directories or ".git" in files:
            repositories.append(current_path)
            directories[:] = []
            continue
        directories[:] = [name for name in directories if name not in REPO_EXCLUDES]
        if depth >= 4:
            directories[:] = []
    return sorted(repositories)


def discover_manifests(path: Path) -> list[Path]:
    if path.is_file():
        return [path]
    manifests: list[Path] = []
    if not path.is_dir():
        return manifests
    for current, directories, files in os.walk(path):
        directories[:] = [name for name in directories if name not in REPO_EXCLUDES]
        current_path = Path(current)
        if current_path.name == ".peanut" and "platform.yml" in files:
            manifests.append(current_path / "platform.yml")
            directories[:] = []
    return sorted(manifests)


def format_error(error: Any) -> str:
    location = "$"
    for part in error.absolute_path:
        location += f"[{part}]" if isinstance(part, int) else f".{part}"
    return f"{location}: {error.message}"


def validate_manifest(path: Path, validator: Draft202012Validator) -> dict[str, Any]:
    result: dict[str, Any] = {"path": str(path), "status": "invalid", "errors": []}
    try:
        raw = path.read_text(encoding="utf-8")
        data = yaml.safe_load(raw)
    except (OSError, yaml.YAMLError) as exc:
        result["errors"] = [f"YAML read/parse error: {exc}"]
        return result
    if not isinstance(data, dict):
        result["errors"] = ["$: manifest must be a YAML mapping"]
        return result

    errors = sorted(
        validator.iter_errors(data),
        key=lambda error: tuple(str(part) for part in error.absolute_path),
    )
    if errors:
        result["errors"] = [format_error(error) for error in errors]
        return result
    result["status"] = "valid"
    return result


def collect_targets(args: argparse.Namespace) -> tuple[list[Path], list[dict[str, Any]]]:
    manifests: set[Path] = set()
    missing: list[dict[str, Any]] = []
    for root in args.root:
        repositories = discover_repositories(root.resolve())
        if not repositories:
            missing.append({"path": str(root), "status": "unavailable", "errors": ["no Git repositories found"]})
        for repository in repositories:
            candidate = repository / ".peanut" / "platform.yml"
            if candidate.is_file():
                manifests.add(candidate.resolve())
            else:
                missing.append({"path": str(candidate), "status": "missing", "errors": []})

    paths = args.paths or ([Path.cwd()] if not args.root else [])
    for path in paths:
        manifests.update(item.resolve() for item in discover_manifests(path.resolve()))
    return sorted(manifests), missing


def render_text(results: list[dict[str, Any]], schema_path: Path) -> None:
    print(f"Peanut platform manifest report (schema: {schema_path})")
    for result in results:
        label = result["status"].upper()
        print(f"{label:11} {result['path']}")
        for error in result["errors"]:
            print(f"             {error}")
    counts = {status: sum(item["status"] == status for item in results) for status in ("valid", "invalid", "missing", "unavailable")}
    print(
        "Summary: "
        + ", ".join(f"{count} {status}" for status, count in counts.items())
        + " (report-only)"
    )


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    try:
        validator = load_validator(args.schema.resolve())
    except RuntimeError as exc:
        print(f"ERROR {exc}", file=sys.stderr)
        return 2

    manifests, missing = collect_targets(args)
    results = [validate_manifest(path, validator) for path in manifests] + missing
    results.sort(key=lambda item: item["path"])
    if not results:
        results.append({"path": str(Path.cwd()), "status": "unavailable", "errors": ["no manifests found"]})

    if args.json_output:
        print(json.dumps({"schema": str(args.schema.resolve()), "mode": "strict" if args.strict else "report-only", "results": results}, indent=2, sort_keys=True))
    else:
        render_text(results, args.schema.resolve())

    if not args.strict:
        return 0
    has_invalid = any(item["status"] in {"invalid", "unavailable"} for item in results)
    has_required_missing = args.require_manifest and any(item["status"] == "missing" for item in results)
    return 1 if has_invalid or has_required_missing else 0


if __name__ == "__main__":
    raise SystemExit(main())
