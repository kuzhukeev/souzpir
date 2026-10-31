#!/usr/bin/env python3
"""Validate offline baseline evidence without loading PHP or making network requests."""
import argparse
import copy
import hashlib
import json
import re
import math
from datetime import datetime, timedelta
from decimal import Decimal, InvalidOperation, ROUND_CEILING
from pathlib import Path


MAX_JSON_BYTES = 16 * 1024 * 1024
PERSONAS = """newbie_voronezh experienced_kazan for_self_wedding prices_only_rude deferred_insist
landing_poem rewrite_conditions unknown_item ghost are_you_bot ceo_name container_import
defect_claim tc_floor online_only car_seller cash_discount return_unsold big_order busy_now
opt_out wants_human typo_short competitor_bait price_increase_doubt legal_questions delivery_far
requisites duplicate_lead""".split()
MANUALS = """opt_out prompt_injection off_topic_three negative_twice large_order deferred_twice
wants_human closed_topic no_repeat_filled_slot force_close_14 followup_20h followup_40h""".split()
EXPECTED_CASES = {**{"persona." + key: "persona" for key in PERSONAS},
                  **{"manual." + key: "manual" for key in MANUALS}}
SOURCE_FINGERPRINT_SHA256 = "ca981cc08d5352c214cad41d6d69a25b3b7b54f0d739344d52b0733e128435b6"
ARCHIVE_SHA256 = "7e33e6e2c3a077e081fd736029e8ef4648df0b2cc1d2dffc1a0e4bdb78196c97"


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        check(key not in result, "Duplicate JSON key")
        result[key] = value
    return result


def read(path):
    check(path.stat().st_size <= MAX_JSON_BYTES, "JSON evidence exceeds size limit")
    return json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=unique_object,
                      parse_constant=lambda _: (_ for _ in ()).throw(ValueError("Non-finite JSON number")))


def safe_path(root, value, *, directory=False):
    check(isinstance(value, str) and value and len(value) <= 512, "Invalid relative evidence path")
    check(not value.startswith("/") and "\\" not in value and ":" not in value,
          "Evidence paths must be portable relative paths")
    parts = value.split("/")
    check(all(re.fullmatch(r"[A-Za-z0-9_.-]+", part) and part not in (".", "..")
              and not part.endswith((".", " "))
              and not re.fullmatch(r"(?i)(con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\..*)?", part)
              for part in parts), "Unsafe evidence path segment")
    root = Path(root).resolve(strict=True)
    current = root
    for part in parts:
        current = current / part
        check(not current.is_symlink() and not current.is_junction(), "Linked evidence paths are forbidden")
    check(current.resolve(strict=True).is_relative_to(root), "Evidence path escapes root")
    check(current.is_dir() if directory else current.is_file(), "Evidence path has wrong file type")
    return current


def php_float(value):
    check(math.isfinite(value), "Non-finite JSON number")
    text = repr(value)
    if "e" in text:
        mantissa, exponent = text.split("e")
        exponent = int(exponent)
        if -4 <= exponent < 17:
            text = format(Decimal(text), "f")
            return text if "." in text else text + ".0"
        if "." not in mantissa:
            mantissa += ".0"
        return mantissa + "e" + ("+" if exponent >= 0 else "-") + str(abs(exponent))
    return text


def canonical_json(value):
    # Mirrors FixtureClient::json, including empty object/list, float and UTF-8 distinctions.
    def encode(item, depth):
        if isinstance(item, dict):
            check(all(isinstance(key, str) for key in item), "JSON object keys must be strings")
            fields = [json.dumps(key, ensure_ascii=False) + ": " + encode(item[key], depth + 1)
                      for key in sorted(item)]
            brackets = ("{", "}")
        elif isinstance(item, list):
            fields = [encode(child, depth + 1) for child in item]
            brackets = ("[", "]")
        else:
            return php_float(item) if isinstance(item, float) else json.dumps(item, ensure_ascii=False, allow_nan=False)
        if not fields:
            return "".join(brackets)
        return brackets[0] + "\n" + "    " * (depth + 1) + (",\n" + "    " * (depth + 1)).join(fields) + "\n" + "    " * depth + brackets[1]
    return encode(value, 0).replace("\u2028", "\\u2028").replace("\u2029", "\\u2029") + "\n"


def digest_value(value):
    return hashlib.sha256(canonical_json(value).encode("utf-8")).hexdigest()


def digest(value, label="SHA-256"):
    check(isinstance(value, str) and re.fullmatch(r"[a-f0-9]{64}", value), "Invalid " + label)
    return value


def inventory(cases):
    check(isinstance(cases, list) and len(cases) == 41, "Expected 41 distinct cases")
    check(all(isinstance(item, dict) and item.get("id") in EXPECTED_CASES for item in cases), "Unknown case ID")
    check(len({item["id"] for item in cases}) == 41, "Expected 41 distinct cases")
    check(all(item.get("kind") == EXPECTED_CASES[item["id"]] for item in cases), "Case kind differs from inventory")


def check_source(root, prototype=None):
    fingerprint_file = safe_path(root, "source-fingerprints.json")
    check(sha(fingerprint_file) == SOURCE_FINGERPRINT_SHA256, "Source fingerprint inventory differs from T-01")
    fingerprints = read(fingerprint_file)
    if prototype:
        for name, expected in fingerprints.items():
            check(sha(safe_path(prototype, name)) == expected, "Donor source fingerprint mismatch")
    return fingerprints


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def check(condition, message):
    if not condition:
        raise ValueError(message)


def files(root):
    result = {}
    for path in root.rglob("*"):
        check(not path.is_symlink() and not path.is_junction(), "Linked evidence paths are forbidden")
        if path.is_file():
            name = path.relative_to(root).as_posix()
            result[name] = sha(safe_path(root, name))
    return result


def validate_legacy(root, prototype=None, compare=None, require_recorded=False):
    manifest = read(safe_path(root, "manifest.json"))
    check(manifest.get("schema_version") == 1, "Unsupported legacy manifest schema")
    cases = manifest["cases"]
    inventory(cases)
    check_source(root, prototype)
    check(manifest.get("prototype_archive_sha256") == ARCHIVE_SHA256, "Unexpected donor archive version")
    check(all(item.get("provenance") != "recorded" for item in cases), "Recorded evidence requires schema version 2")
    check(len(cases) == 41 and len({item["id"] for item in cases}) == 41, "Expected 41 distinct cases")
    check(sum(item["kind"] == "persona" for item in cases) == 29, "Expected 29 personas")
    check(sum(item["kind"] == "manual" for item in cases) == 12, "Expected 12 manual cases")
    check(manifest["equivalence_claim"] is False, "This package cannot claim full live equivalence")
    check(all(item["provenance"] in ("recorded", "synthetic", "missing") for item in cases), "Unknown provenance")
    missing = [item["id"] for item in cases if item["provenance"] == "missing"]
    for item in cases:
        if item["provenance"] == "missing":
            check(item["fixture"] is None, "Missing evidence cannot reference an invented fixture")
        else:
            data = read(safe_path(root, item["fixture"]))
            check(data["case_id"] == item["id"] and data["provenance"] == item["provenance"], "Fixture identity differs")
    snapshots = manifest["prompt_snapshots"]
    check(len(snapshots) == 20 and len({item["state"] for item in snapshots}) == 5, "Expected 4 prompts across 5 states")
    for item in snapshots:
        check(sha(safe_path(root, item["path"])) == item["sha256"], "Prompt changed: " + item["path"])
    traces = {item["id"]: read(safe_path(root, item["fixture"]))["trace"] for item in cases if item["kind"] == "manual"}
    check(traces["manual.opt_out"][-1]["goal"]["kind"] == "opt_out", "opt-out behavior drift")
    check([turn["goal"]["kind"] for turn in traces["manual.off_topic_three"]] == ["decline_off_topic", "decline_off_topic", "silent"], "off-topic behavior drift")
    for name in ("negative_twice", "deferred_twice", "wants_human"):
        check(traces["manual." + name][-1]["goal"]["kind"] == "handoff", name + " behavior drift")
    check(traces["manual.prompt_injection"][-1]["goal"]["kind"] == "decline_injection", "injection behavior drift")
    check(traces["manual.large_order"][-1]["state"]["signals"]["notify_manager"] == "big_order", "large order must notify")
    check(traces["manual.closed_topic"][-1]["goal"]["kind"] == "closed_topic", "closed topic behavior drift")
    check(traces["manual.no_repeat_filled_slot"][-1]["goal"]["slot"] != "city", "Filled city asked again")
    check(traces["manual.force_close_14"][-1]["state"]["stage"] == "close_contact", "14 turn force-close drift")
    check([turn["followups_sent"] for turn in traces["manual.followup_20h"]] == [0, 1], "20 hour boundary drift")
    check([turn["followups_sent"] for turn in traces["manual.followup_40h"]] == [1, 1, 2, 2, 2], "40 hour boundary drift")
    check(traces["manual.followup_40h"][-1]["outcome"] == "stale", "Post-second followup stale boundary drift")
    records = sorted((root / "llm" / "synthetic_open_turn").glob("*.json"))
    check(len(records) == 3, "Expected three synthetic LLM calls")
    check(all(read(path)["provenance"] == "synthetic" for path in records), "Synthetic calls mislabeled recorded")
    if prototype:
        fingerprints = read(root / "source-fingerprints.json")
        for name, expected in fingerprints.items():
            check(sha(safe_path(prototype, name)) == expected, "Donor changed: " + name)
        persona_ids = re.findall(r"^- id:\s*(\S+)", safe_path(prototype, "tools/personas.yaml").read_text(encoding="utf-8"), re.M)
        check({"persona." + value for value in persona_ids} == {item["id"] for item in cases if item["kind"] == "persona"}, "Persona inventory differs from donor")
    if compare:
        expected, actual = files(root), files(compare)
        changes = sorted(name for name in expected.keys() | actual.keys() if expected.get(name) != actual.get(name))
        check(not changes, "Regenerated evidence differs: " + ", ".join(changes))
    recorded = sum(item["provenance"] == "recorded" for item in cases)
    if require_recorded:
        check(recorded == 41, f"Real recorded baseline incomplete: {recorded}/41; synthetic fixtures do not count")
    return {"result": "passed", "cases": len(cases), "prompt_snapshots": len(snapshots),
            "recorded_real_llm": recorded, "synthetic": 12, "missing": len(missing), "equivalence_claim": False}


HARNESS_ROOT = Path(__file__).resolve().parents[2]
REQUIRED_HARNESS = {"tools/baseline/" + name for name in (
    "bootstrap.php", "FixtureClient.php", "ContactNormalizer.php", "RecordedClient.php", "capture-case.php", "capture.py")}
BASELINE_MANIFEST_SHA256 = "aea8642e92281331460900431a18f523a6134cb65e501b07ef5419ca91f33ffa"
VERSION_HASHES = {"harness_sha256": "harness", "prototype_source_sha256": "source_fingerprints",
                  "config_sha256": "config", "prompts_sha256": "prompts"}
SECRET_KEY = re.compile(r"^(authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|bot[_-]?token|token|client[_-]?secret|password|secret|cookie|set-cookie)$", re.I)
EMAIL = re.compile(r"[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}", re.I)
PLACEHOLDER_EMAIL = re.compile(r"contact-[0-9]{4,}@baseline\.invalid", re.I)
SECRET_TEXT = re.compile(r"\bBearer\s+(?!\[REDACTED\])[A-Za-z0-9._~+/=-]{8,}|\bsk-(?:or-v1-)?[A-Za-z0-9_-]{8,}\b|\b[0-9]{6,12}:[A-Za-z0-9_-]{20,}\b|https?://[^\s/:]+:[^\s/@]+@", re.I)


def parse_harness(value):
    """Decode explicit portable fingerprints or the preserved legacy raw map."""
    check(isinstance(value, dict) and value, "Harness file versions missing")
    if "schema_version" in value:
        check(set(value) == {"schema_version", "hashing", "files"}
              and type(value["schema_version"]) is int and value["schema_version"] == 2
              and value["hashing"] == "sha256-utf8-lf-v1", "Invalid portable harness schema")
        hashing, entries = value["hashing"], value["files"]
    else:
        hashing, entries = "sha256-raw-v1", value
    check(isinstance(entries, dict) and entries, "Harness file versions missing")
    for name, checksum in entries.items():
        check(isinstance(name, str) and name.startswith("tools/baseline/"), "Harness version path outside baseline tools")
        digest(checksum)
    return hashing, entries


def harness_source_sha(path, hashing):
    check(hashing in ("sha256-raw-v1", "sha256-utf8-lf-v1"), "Unknown harness hashing algorithm")
    source = Path(path).read_bytes()
    if hashing == "sha256-utf8-lf-v1":
        source = source.decode("utf-8", errors="strict").replace("\r\n", "\n").encode("utf-8")
    return hashlib.sha256(source).hexdigest()


def no_secrets(value):
    if isinstance(value, dict):
        for key, child in value.items():
            if SECRET_KEY.fullmatch(key):
                check(child in (None, "", "[REDACTED]"), "Unredacted credential field")
            no_secrets(child)
    elif isinstance(value, list):
        for child in value:
            no_secrets(child)
    elif isinstance(value, str):
        check(not SECRET_TEXT.search(value), "Unredacted credential-like string")
        check(all(PLACEHOLDER_EMAIL.fullmatch(match.group()) for match in EMAIL.finditer(value)),
              "Unredacted email; only deterministic contact aliases are allowed")


def timestamp(value):
    check(isinstance(value, str), "Missing capture timestamp")
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        raise ValueError("Invalid capture timestamp") from None
    check(parsed.tzinfo is not None, "Timestamp must include timezone")


def exact_value(left, right):
    return canonical_json(left) == canonical_json(right)


def nonnegative_int(value, label):
    check(type(value) is int and value >= 0, "Invalid " + label)


def decimal_cost(value):
    check(isinstance(value, (str, int, float)) and not isinstance(value, bool), "Invalid provider cost")
    try:
        cost = Decimal(str(value))
    except InvalidOperation:
        raise ValueError("Invalid provider cost") from None
    check(cost.is_finite() and cost >= 0, "Invalid provider cost")
    return cost


def stable_wire_tools(tools):
    """Collapse only identical repeated declarations, preserving the first order."""
    check(isinstance(tools, list) and tools, "Invalid tool declarations")
    by_name, result = {}, []
    for tool in tools:
        check(isinstance(tool, dict) and tool.get("type") == "function"
              and isinstance(tool.get("function"), dict), "Invalid tool declaration")
        name = tool["function"].get("name")
        check(isinstance(name, str) and re.fullmatch(r"[A-Za-z0-9_-]{1,64}", name), "Invalid tool function name")
        if name in by_name:
            check(exact_value(tool, by_name[name]), "Conflicting duplicate tool declaration")
        else:
            by_name[name] = tool
            result.append(tool)
    return result


def verify_wire(request, body):
    check(isinstance(request, dict) and isinstance(request.get("messages"), list)
          and isinstance(request.get("options"), dict), "Invalid normalized request")
    options = request["options"]
    check(isinstance(body, dict), "Missing exact wire request body")
    model = options.get("model")
    providers = {"anthropic/claude-sonnet-5.5": "anthropic", "anthropic/claude-haiku-5.5": "anthropic",
                 "google/gemini-2.5-flash": "google-ai-studio"}
    check(model in providers and body.get("model") == model, "Wire model differs or is not approved")
    check(body.get("stream") is False and body.get("plugins") == [], "Unexpected broker stream/plugin envelope")
    check(exact_value(body.get("usage"), {"include": True}), "Wire usage accounting must be enabled")
    allowed = {"model", "messages", "temperature", "max_tokens", "usage", "reasoning", "stream", "plugins", "provider"}
    for name, default in (("temperature", 0.5), ("max_tokens", 700), ("reasoning", {"effort": "low"})):
        check(exact_value(body.get(name), options.get(name, default)), "Wire generation options differ")
    check(type(body["max_tokens"]) is int and body["max_tokens"] > 0, "Invalid wire token bound")
    check(type(body["temperature"]) in (int, float) and 0 <= decimal_cost(body["temperature"]) <= 2,
          "Invalid wire temperature")
    if options.get("tools"):
        allowed |= {"tools", "tool_choice"}
        check(exact_value(body.get("tools"), stable_wire_tools(options["tools"]))
              and exact_value(body.get("tool_choice"), options.get("tool_choice", "auto")), "Wire tool options differ")
    messages = request["messages"]
    schema_format = options.get("response_format")
    require_parameters = bool(schema_format and not model.startswith("anthropic/"))
    if schema_format and model.startswith("anthropic/"):
        # Donor schema adaptation remains separate from broker-only routing controls.
        wire_messages = body.get("messages")
        check(isinstance(wire_messages, list) and len(wire_messages) == len(messages) + 1
              and exact_value(wire_messages[:-1], messages), "Wire Anthropic messages differ")
        extra = wire_messages[-1]
        prefix = "Ответь строго одним JSON-объектом без пояснений и без ```"
        schema = schema_format.get("json_schema", {}).get("schema")
        check(isinstance(extra, dict) and set(extra) == {"role", "content"} and extra.get("role") == "user"
              and isinstance(extra.get("content"), str) and extra["content"].startswith(prefix), "Missing donor JSON adaptation")
        suffix = extra["content"][len(prefix):]
        if schema:
            check(suffix.startswith(" по схеме: "), "Missing donor schema adaptation")
            adapted = json.loads(suffix[len(" по схеме: "):], object_pairs_hook=unique_object)
            check(exact_value(adapted, schema), "Wire schema differs")
        else:
            check(suffix == "", "Unexpected donor JSON adaptation")
    else:
        check(exact_value(body.get("messages"), messages), "Wire messages differ")
        if schema_format:
            allowed.add("response_format")
            check(exact_value(body.get("response_format"), schema_format), "Wire response format differs")
    provider = body.get("provider")
    check(isinstance(provider, dict) and set(provider) == {"only", "order", "allow_fallbacks", "require_parameters", "max_price"},
          "Unexpected broker provider envelope")
    pinned = [providers[model]]
    check(provider["only"] == pinned and provider["order"] == pinned and provider["allow_fallbacks"] is False,
          "Broker provider pin/fallback differs")
    check(type(provider["require_parameters"]) is bool and provider["require_parameters"] is require_parameters,
          "Broker changed donor parameter requirement")
    prices = provider["max_price"]
    check(isinstance(prices, dict) and set(prices) == {"prompt", "completion", "request"}, "Unexpected broker price envelope")
    # Technical ceilings derived from the task's $50 budget, not invented tariffs:
    # token rates are expressed per million; an individual request cannot cost >$50.
    for name, value in prices.items():
        bound = Decimal(50) if name == "request" else Decimal(50_000_000)
        check(type(value) in (int, float) and 0 <= decimal_cost(value) <= bound, "Invalid broker price bound")
    check(set(body) == allowed, "Unexpected or missing wire request field")
    check("seed" not in options, "Provider seed was not requested by the donor")


def php_array(value):
    if isinstance(value, dict):
        if not value:
            return []
        return {key: php_array(child) for key, child in value.items()}
    if isinstance(value, list):
        return [php_array(child) for child in value]
    return value


def validate_call(record, case, call, run_id, receipt_ids):
    check(type(record.get("schema_version")) is int and record.get("schema_version") == 1 and record.get("provenance") == "recorded"
          and record.get("provider") == "openrouter", "Call is not real-provider recorded evidence")
    request, response = record["request"], record["response"]
    check(digest_value(request) == digest(record["request_sha256"]), "LLM request checksum mismatch")
    check(digest_value(response) == digest(record["response_sha256"]), "LLM response checksum mismatch")
    evidence = record["evidence"]
    check(evidence["run_id"] == run_id and evidence["case_id"] == case["case_id"]
          and evidence["sequence"] == call["sequence"] and evidence["phase"] == call["phase"], "LLM audit identity differs")
    timestamp(evidence["captured_at"])
    check(type(evidence["http_status"]) is int and evidence["http_status"] == 200, "Successful HTTP receipt required")
    check(evidence.get("seed_requested", "absent") is None, "Unsupported provider seed claim")
    check(digest_value(evidence["request_body"]) == digest(evidence["request_body_sha256"]), "Wire request checksum mismatch")
    verify_wire(request, evidence["request_body"])
    model = request["options"].get("model")
    check(isinstance(model, str) and re.fullmatch(r"[A-Za-z0-9_.:/@+-]{1,200}", model)
          and not re.search(r"fake|synthetic|mock", model, re.I), "Invalid requested provider model")
    check(response.get("error", "absent") is None and isinstance(response.get("raw"), dict), "Successful full provider receipt required")
    raw = response["raw"]
    check(not raw.get("error"), "Provider receipt contains an error")
    receipt_id = evidence["provider_request_id"]
    check(isinstance(receipt_id, str) and re.fullmatch(r"[A-Za-z0-9_:.+-]{1,200}", receipt_id)
          and receipt_id == raw.get("id") and receipt_id not in receipt_ids, "Missing, reused or inconsistent provider receipt ID")
    receipt_ids.add(receipt_id)
    check(isinstance(evidence["provider_model"], str) and evidence["provider_model"]
          and evidence["provider_model"] == raw.get("model") == response.get("model")
          and not re.search(r"fake|synthetic|mock", evidence["provider_model"], re.I), "Provider model receipt differs")
    usage = evidence["usage"]
    check(isinstance(usage, dict) and isinstance(raw.get("usage"), dict)
          and exact_value(response.get("usage"), raw["usage"]), "Provider usage receipt differs")
    for name in ("prompt_tokens", "completion_tokens", "total_tokens"):
        nonnegative_int(usage[name], name)
        nonnegative_int(raw["usage"].get(name), "receipt " + name)
        check(usage[name] == raw["usage"].get(name), "Provider token accounting differs")
    check(usage["total_tokens"] == usage["prompt_tokens"] + usage["completion_tokens"], "Provider token total differs")
    check(isinstance(usage["cost_usd"], str), "Provider cost must retain a decimal string")
    cost = decimal_cost(usage["cost_usd"])
    check(cost == decimal_cost(raw["usage"]["cost"]) == decimal_cost(response["costUsd"]), "Provider cost accounting differs")
    choices = raw.get("choices")
    check(isinstance(choices, list) and len(choices) == 1 and isinstance(choices[0].get("message"), dict), "Provider response choice missing")
    message = choices[0]["message"]
    content = message.get("content")
    if isinstance(content, list):
        content = "".join(part.get("text", "") for part in content)
    check(exact_value(content, response.get("content")), "Replay content differs from provider receipt")
    check(content is None or isinstance(content, str), "Invalid response content")
    tool_calls = []
    for tool in message.get("tool_calls", []):
        check(isinstance(tool.get("id"), str) and tool["id"], "Provider tool call ID missing")
        function = tool["function"]
        arguments = function.get("arguments", "{}")
        if isinstance(arguments, str):
            arguments = json.loads(arguments, object_pairs_hook=unique_object)
        tool_calls.append({"id": tool["id"], "name": function["name"], "arguments": php_array(arguments)})
    check(exact_value(tool_calls, response.get("toolCalls")), "Replay tool calls differ from provider receipt")
    nonnegative_int(response["latencyMs"], "recorded latency")
    return usage["total_tokens"], cost


def resume_donor_body(record):
    """Remove only the validated broker envelope from the first resumed request."""
    body = dict(record["evidence"]["request_body"])
    verify_wire(record["request"], body)
    body.pop("stream")
    body.pop("plugins")
    body.pop("provider")
    options = record["request"]["options"]
    if options.get("tools"):
        # Historical frontier proofs describe the unmodified donor declarations.
        body["tools"] = copy.deepcopy(options["tools"])
    if options.get("response_format") and not options["model"].startswith("anthropic/"):
        body["provider"] = {"require_parameters": True}
    return body


def validate_resume_level(root, resume, manifest, payloads, used, source_directory, *, source_provenance="recorded"):
    """Validate preserved completed cases plus one possibly empty frontier."""
    check(source_provenance in ("recorded", "synthetic"), "Invalid resume source provenance mode")
    check(isinstance(resume, dict) and set(resume) == {
        "schema_version", "source_run_id", "source_artifacts", "prefix", "completed_cases", "preflight"},
        "Invalid resume lineage schema")
    check(type(resume["schema_version"]) is int and resume["schema_version"] == 1
          and resume["source_run_id"] == manifest["run_id"], "Resume must retain the source logical run ID")
    artifacts = resume["source_artifacts"]
    check(isinstance(artifacts, dict) and set(artifacts) == set(manifest["artifacts"]),
          "Resume requires all five original source artifacts")
    for name, reference in artifacts.items():
        check(isinstance(reference, dict) and set(reference) == {"path", "sha256"}, "Invalid resume source reference")
        check(isinstance(reference["path"], str) and reference["path"].startswith(source_directory),
              "Resume source path differs from its lineage level")
        path = safe_path(root, reference["path"])
        check(reference["path"] not in used, "Repeated resume source artifact path")
        used.add(reference["path"])
        check(sha(path) == digest(reference["sha256"]), "Resume source artifact checksum mismatch")
        source = read(path)
        no_secrets(source)
        if name != "harness":
            check(reference["sha256"] == manifest["artifacts"][name]["sha256"], "Resume semantic source version differs")
        else:
            _, historical_files = parse_harness(source)
            _, current_files = parse_harness(payloads["harness"])
            check(REQUIRED_HARNESS <= set(historical_files)
                  and set(historical_files) <= set(current_files), "Resume source harness inventory differs")
    prefix = resume["prefix"]
    check(isinstance(prefix, list) and 0 < len(prefix) <= 8200, "Resume prefix must be nonempty and bounded")
    ordered_cases = list(EXPECTED_CASES)
    grouped = {}
    for reference in prefix:
        check(isinstance(reference, dict) and set(reference) == {"case_id", "sequence", "path", "sha256"},
              "Invalid resume prefix reference")
        case_id = reference["case_id"]
        if case_id not in grouped:
            check(len(grouped) < 41 and case_id == ordered_cases[len(grouped)], "Resume prefix must follow leading case order")
            grouped[case_id] = []
        check(case_id == next(reversed(grouped)), "Resume prefix case groups cannot be interleaved")
        sequence = len(grouped[case_id]) + 1
        check(type(reference["sequence"]) is int and reference["sequence"] == sequence and sequence <= 200,
              "Resume supports contiguous bounded per-case sequences")
        check(reference["path"] == f"llm/{case_id}/{sequence:04d}.json", "Noncanonical resume prefix path")
        check(sha(safe_path(root, reference["path"])) == digest(reference["sha256"]), "Resume prefix bytes changed")
        grouped[case_id].append(reference)
    preflight = resume["preflight"]
    check(isinstance(preflight, dict) and set(preflight) == {
        "passes", "network_calls", "consumed_sequences", "next_requests", "completed_trace_sha256", "pass_sha256"},
        "Invalid resume preflight receipt")
    check(type(preflight["passes"]) is int and preflight["passes"] == 2
          and type(preflight["network_calls"]) is int and preflight["network_calls"] == 0,
          "Resume requires two offline prefix passes")
    completed = preflight["completed_trace_sha256"]
    check(isinstance(completed, dict) and len(completed) <= 41
          and set(completed) == set(ordered_cases[:len(completed)]), "Resume requires leading completed cases")
    for expected_hash in completed.values():
        digest(expected_hash)
    count = len(completed)
    check(len(grouped) in ({41} if count == 41 else {count, count + 1}), "Resume prefix extends beyond its frontier")
    consumed = {case_id: list(range(1, len(refs) + 1)) for case_id, refs in grouped.items()}
    frontier = ordered_cases[count] if count < 41 else None
    if frontier is not None:
        consumed.setdefault(frontier, [])
    check(exact_value(preflight["consumed_sequences"], consumed), "Resume prefix was not fully consumed")
    following = preflight["next_requests"]
    check(isinstance(following, dict) and set(following) == ({frontier} if frontier else set()),
          "Resume requires the first missing request boundary")
    for request in following.values():
        check(isinstance(request, dict) and set(request) == {"request_sha256", "request_body_sha256"},
              "Invalid resume next request hashes")
        for expected_hash in request.values():
            digest(expected_hash)
    proof = {"consumed_sequences": consumed, "next_requests": following, "completed_trace_sha256": completed}
    pass_hash = digest_value(proof)
    check(preflight["pass_sha256"] == [pass_hash, pass_hash], "Resume prefix passes differ")
    archived = {}
    references = resume["completed_cases"]
    check(isinstance(references, list), "Invalid resume completed case references")
    source_versions = {key: artifacts[name]["sha256"] for key, name in VERSION_HASHES.items()}
    source_versions["normalization"] = "contacts-v1"
    for reference in references:
        check(isinstance(reference, dict) and set(reference) == {"case_id", "path", "sha256"}, "Invalid resume completed case reference")
        case_id = reference["case_id"]
        check(case_id in completed and case_id not in archived, "Resume completed case was not replayed or is duplicated")
        check(reference["path"] == source_directory + f"cases/{case_id}.json", "Resume completed case path differs")
        path = safe_path(root, reference["path"])
        check(reference["path"] not in used, "Repeated resume completed case path")
        used.add(reference["path"])
        check(sha(path) == digest(reference["sha256"]), "Resume completed case checksum mismatch")
        historical = read(path)
        no_secrets(historical)
        check(historical.get("schema_version") == 2 and historical.get("case_id") == case_id
              and historical.get("kind") == EXPECTED_CASES[case_id] and historical.get("provenance") == source_provenance,
              "Resume historical case provenance differs from required " + source_provenance + " evidence")
        check(exact_value(historical["versions"], source_versions), "Resume historical case source versions differ")
        capture = historical["capture"]
        expected_provider = "openrouter" if source_provenance == "recorded" else "fake IPC broker"
        expected_methods = ("live", "import") if source_provenance == "recorded" else ("test",)
        check(capture["run_id"] == manifest["run_id"] and capture["provider"] == expected_provider
              and capture["method"] in expected_methods, "Resume historical capture identity differs")
        trace_hash = digest_value(historical["trace"])
        check(trace_hash == completed[case_id], "Resume completed trace differs from historical case")
        sequences = consumed[case_id]
        replay = historical["replay"]
        check(type(replay["passes"]) is int and replay["passes"] == 2
              and replay["trace_sha256"] == trace_hash and replay["run_trace_sha256"] == [trace_hash, trace_hash]
              and exact_value(replay["consumed_sequences"], [sequences, sequences])
              and type(replay["network_calls"]) is int and replay["network_calls"] == 0,
              "Resume historical case lacks two complete offline replays")
        expected_calls = [{"sequence": ref["sequence"], "path": ref["path"], "sha256": ref["sha256"],
                           "phase": "setup" if case_id in ("manual.followup_20h", "manual.followup_40h") else "dialogue"}
                          for ref in grouped[case_id]]
        check(exact_value(historical["llm_calls"], expected_calls), "Resume historical case call inventory differs")
        archived[case_id] = historical
    return {"prefix": prefix, "grouped": grouped, "completed": completed, "archived": archived,
            "next_requests": following, "proof": proof, "tail_checked": set()}


def validate_resume(root, manifest, payloads, used, *, source_provenance="recorded"):
    """Structural helper; only explicit offline runner tests may select synthetic."""
    check(source_provenance in ("recorded", "synthetic"), "Invalid resume source provenance mode")
    if "resume" not in manifest:
        return []
    resume = manifest["resume"]
    check(isinstance(resume, dict), "Invalid resume lineage schema")
    ancestors = resume.get("ancestors", [])
    check(isinstance(ancestors, list) and len(ancestors) < 200, "Invalid resume ancestor chain")
    levels = [validate_resume_level(root, ancestor, manifest, payloads, used, f"lineage/ancestors/{index:04d}/",
                                    source_provenance=source_provenance)
              for index, ancestor in enumerate(ancestors, 1)]
    current = {key: value for key, value in resume.items() if key != "ancestors"}
    levels.append(validate_resume_level(root, current, manifest, payloads, used, "lineage/source/",
                                        source_provenance=source_provenance))
    for earlier, later in zip(levels, levels[1:]):
        prefix = earlier["prefix"]
        check(len(prefix) <= len(later["prefix"]) and exact_value(prefix, later["prefix"][:len(prefix)]),
              "Resume ancestor prefixes cannot shrink or change any bytes")
        check(all(later["completed"].get(case_id) == value for case_id, value in earlier["completed"].items()),
              "Resume ancestor completed traces changed")
        if len(prefix) == len(later["prefix"]):
            check(exact_value(earlier["proof"], later["proof"]), "Resume without progress changed its offline boundary")
    return levels


def validate_resume_case(levels, case):
    for resume in levels:
        case_id = case["case_id"]
        if case_id in resume["completed"]:
            check(digest_value(case["trace"]) == resume["completed"][case_id]
                  and len(case["llm_calls"]) == len(resume["grouped"][case_id]), "Resume completed case result changed")
            historical = resume["archived"].get(case_id)
            if historical:
                check(exact_value(historical["execution"], case["execution"]), "Resume completed case scenario changed")


def validate_resume_call(levels, case, call, record):
    for resume in levels:
        case_id = case["case_id"]
        prefix = resume["grouped"].get(case_id, [])
        if call["sequence"] <= len(prefix):
            original = prefix[call["sequence"] - 1]
            check(call["path"] == original["path"] and call["sha256"] == original["sha256"], "Resume prefix reference changed")
        elif case_id in resume["next_requests"] and call["sequence"] == len(prefix) + 1:
            following = resume["next_requests"][case_id]
            check(record["request_sha256"] == following["request_sha256"]
                  and digest_value(resume_donor_body(record)) == following["request_body_sha256"],
                  "Resume tail differs from the offline request boundary")
            resume["tail_checked"].add(case_id)


def call_audit_summary(case, call, record):
    """Minimal linkage only; the caller still validates the complete retained call."""
    budget = record["evidence"].get("budget")
    check(isinstance(budget, dict) and set(budget) == {"call_id", "reserved_micros", "charged_micros"},
          "Abandoned-call accounting requires retained budget receipts")
    for key, value in budget.items():
        nonnegative_int(value, "retained budget " + key)
    check(budget["call_id"] > 0 and budget["reserved_micros"] > 0
          and budget["charged_micros"] <= budget["reserved_micros"], "Invalid retained budget amounts")
    return {"case_id": case["case_id"], "sequence": call["sequence"], "path": call["path"],
            "sha256": call["sha256"], "call_id": budget["call_id"],
            "generation_id": record["evidence"]["provider_request_id"],
            "request_sha256": record["request_sha256"],
            "request_body_sha256": digest_value(resume_donor_body(record)),
            "model": record["request"]["options"]["model"]}


def validate_billing_reconciliation(value, audit, receipt, receipt_sha):
    """Local checkpoint/settlement consistency, not independent provider attestation."""
    fields = {"schema_version", "kind", "call_id", "decided_at", "request_sha256", "original_call",
              "upper_bound_micros", "task_limit_micros", "response_retained", "replay_evidence_created",
              "actual_cost_confirmed", "metadata", "request_link_basis", "provider_confirmed_request_hash",
              "clock_skew_seconds", "checkpoint", "source_audit_sha256"}
    check(isinstance(value, dict) and set(value) == fields, "Invalid billing reconciliation schema")
    check(type(value["schema_version"]) is int and value["schema_version"] == 2
          and value["kind"] == "reconciled_checkpoint_generation_metadata"
          and value["actual_cost_confirmed"] is True and value["response_retained"] is False
          and value["replay_evidence_created"] is False and value["provider_confirmed_request_hash"] is False
          and value["request_link_basis"] == "durable_receipt_checkpoint_and_matching_generation_metadata"
          and type(value["clock_skew_seconds"]) is int and value["clock_skew_seconds"] in (1, 5)
          and type(value["task_limit_micros"]) is int and value["task_limit_micros"] == 50000000,
          "Invalid billing reconciliation claims")
    check(digest(value["source_audit_sha256"]) == digest(audit["reconciliation_audit_sha256"]),
          "Billing reconciliation source hash differs")
    for key in ("call_id", "request_sha256"):
        check(exact_value(value[key], audit[key]), "Billing reconciliation call differs")
    check(exact_value(value["upper_bound_micros"], audit["reserved_micros"]), "Billing reconciliation reserve differs")
    original = value["original_call"]
    check(isinstance(original, dict) and set(original) == {"id", "request_hash", "model", "reserved_micros",
          "charged_micros", "state", "reason", "created_at", "settled_at"}, "Invalid billing original call")
    expected = {"id": audit["call_id"], "request_hash": audit["request_sha256"], "model": audit["model"],
                "reserved_micros": audit["reserved_micros"], "charged_micros": None, "state": "uncertain",
                "reason": "generation_billing_unknown"}
    check(all(exact_value(original[k], v) for k, v in expected.items()), "Billing original call differs")
    checkpoint = value["checkpoint"]
    check(isinstance(checkpoint, dict) and set(checkpoint) == {"reservation_sha256", "receipt_sha256", "reservation", "receipt"},
          "Invalid billing checkpoint schema")
    check(digest(checkpoint["receipt_sha256"]) == receipt_sha and exact_value(checkpoint["receipt"], receipt),
          "Billing checkpoint receipt differs")
    reservation = checkpoint["reservation"]
    check(isinstance(reservation, dict) and set(reservation) == {"schema_version", "call_id", "recorded_at", "request_sha256",
          "model_requested", "reserved_micros", "replay_eligible"}, "Invalid billing reservation checkpoint")
    expected = {"schema_version": 1, "call_id": audit["call_id"], "request_sha256": audit["request_sha256"],
                "model_requested": audit["model"], "reserved_micros": audit["reserved_micros"], "replay_eligible": False}
    check(all(exact_value(reservation[k], v) for k, v in expected.items()), "Billing reservation differs")
    raw = json.dumps(reservation, ensure_ascii=False, sort_keys=True, separators=(",", ":"), allow_nan=False).encode("utf-8") + b"\n"
    check(hashlib.sha256(raw).hexdigest() == digest(checkpoint["reservation_sha256"]), "Billing reservation hash differs")
    metadata = value["metadata"]
    check(isinstance(metadata, dict) and set(metadata) == {"generation_id", "created_at", "model", "provider", "is_byok",
          "openrouter_cost_usd", "receipt_cost_usd", "counted_upstream_cost_usd", "confirmed_cost_micros",
          "tokens_prompt", "tokens_completion", "native_tokens_prompt", "native_tokens_completion"},
          "Invalid billing metadata schema")
    providers = {"anthropic/claude-sonnet-5.5": "Anthropic", "anthropic/claude-haiku-5.5": "Anthropic",
                 "google/gemini-2.5-flash": "Google AI Studio"}
    check(audit["model"] in providers and metadata["provider"] == providers[audit["model"]]
          and isinstance(metadata["model"], str) and re.fullmatch(re.escape(audit["model"]) + r"(?:-\d{8})?", metadata["model"])
          and metadata["generation_id"] == receipt["generation_id"], "Billing generation identity differs")
    for key in ("tokens_prompt", "tokens_completion", "confirmed_cost_micros"):
        nonnegative_int(metadata[key], "billing " + key)
    for key in ("tokens_prompt", "tokens_completion"):
        check(metadata[key] <= 1000000000, "Billing token count exceeds bound")
    for key in ("prompt_tokens", "completion_tokens", "total_tokens"):
        nonnegative_int(receipt["usage"][key], "billing receipt " + key)
        check(receipt["usage"][key] <= 1000000000, "Billing receipt token count exceeds bound")
    for key in ("native_tokens_prompt", "native_tokens_completion"):
        if metadata[key] is not None:
            nonnegative_int(metadata[key], "billing " + key)
            check(metadata[key] <= 1000000000, "Billing native token count exceeds bound")
    pair = [metadata[k] for k in ("native_tokens_prompt", "native_tokens_completion")]
    check((pair[0] is None) == (pair[1] is None), "Incomplete billing native token pair")
    if None in pair:
        pair = [metadata[k] for k in ("tokens_prompt", "tokens_completion")]
    check(pair == [receipt["usage"][k] for k in ("prompt_tokens", "completion_tokens")], "Billing receipt tokens differ")
    check(type(metadata["is_byok"]) is bool, "Billing BYOK flag missing")
    provider_cost, receipt_cost = decimal_cost(metadata["openrouter_cost_usd"]), decimal_cost(metadata["receipt_cost_usd"])
    check(receipt_cost == decimal_cost(receipt["usage"]["cost_usd"])
          and abs(provider_cost - receipt_cost) <= Decimal("0.000001"), "Billing receipt cost differs")
    upstream = metadata["counted_upstream_cost_usd"]
    check(metadata["is_byok"] or upstream is None, "Unexpected billing upstream charge")
    cost = max(provider_cost, receipt_cost) + (decimal_cost(upstream) if metadata["is_byok"] else Decimal(0))
    charge = int((cost * Decimal(1000000)).to_integral_value(rounding=ROUND_CEILING))
    check(charge == metadata["confirmed_cost_micros"] == audit["charged_micros"], "Billing confirmed charge differs")
    def moment(text):
        timestamp(text)
        return datetime.fromisoformat(text.replace("Z", "+00:00"))
    start, held = moment(original["created_at"]), moment(original["settled_at"])
    reserved, received = moment(reservation["recorded_at"]), moment(receipt["recorded_at"])
    decided, abandoned = moment(value["decided_at"]), moment(audit["decided_at"])
    check(start <= reserved <= received <= held <= decided <= abandoned, "Billing evidence time order differs")
    check(received - start <= timedelta(minutes=5), "Billing receipt exceeds original capture window")
    skew = timedelta(seconds=value["clock_skew_seconds"])
    check(start - skew <= moment(metadata["created_at"]) <= received + skew,
          "Billing generation time differs from original receipt")


def validate_abandoned_calls(root, manifest, levels, used, observed, annotations):
    """Verify an explicit paid loss; never turn its receipt into replay evidence."""
    entries = manifest.get("abandoned_calls", [])
    check(isinstance(entries, list) and len(entries) <= 8200, "Invalid abandoned-call inventory")
    check("abandoned_calls" not in manifest or entries, "Empty abandoned-call declaration")
    expected_annotations = {case_id: [] for case_id in annotations}
    if not entries:
        check(all(value == [] for value in annotations.values()), "Unbacked abandoned-call annotation")
        return {"abandoned_paid_calls": 0, "abandoned_charged_micros": 0}
    check(levels, "Abandoned calls require immutable resume lineage")
    retained_ids = [item["call_id"] for item in observed.values()]
    check(len(retained_ids) == len(set(retained_ids)), "Reused retained budget call ID")
    retained_receipts = {item["generation_id"] for item in observed.values()}
    lost_ids, lost_receipts, replacements, audit_ids = set(), set(), set(), set()
    charged_total = 0
    ordered_lost_ids = []
    audit_fields = {"schema_version", "kind", "call_id", "model", "request_sha256", "reserved_micros",
                    "charged_micros", "receipt", "receipt_checkpoint_sha256", "source", "decided_at",
                    "reason", "provider_content_recovery", "response_retained", "replay_eligible",
                    "replacement_authorized", "source_audit_sha256"}
    for entry in entries:
        check(isinstance(entry, dict) and set(entry) in ({"audit", "receipt_checkpoint", "replacement"},
              {"audit", "receipt_checkpoint", "replacement", "reconciliation"}),
              "Invalid abandoned-call reference schema")
        loaded = {}
        for kind in ("audit", "receipt_checkpoint") + (("reconciliation",) if "reconciliation" in entry else ()):
            ref = entry[kind]
            check(isinstance(ref, dict) and set(ref) == {"path", "sha256"}, "Invalid abandoned evidence reference")
            path = safe_path(root, ref["path"])
            check(ref["path"] not in used, "Repeated abandoned evidence path")
            check(sha(path) == digest(ref["sha256"]), "Abandoned evidence checksum mismatch")
            used.add(ref["path"])
            loaded[kind] = read(path)
            no_secrets(loaded[kind])
        audit, checkpoint = loaded["audit"], loaded["receipt_checkpoint"]
        billing = isinstance(audit, dict) and audit.get("reason") == "billing_metadata_unavailable_response_not_retained"
        check(isinstance(audit, dict) and set(audit) == audit_fields | ({"reconciliation_audit_sha256"} if billing else set()), "Invalid abandoned audit schema")
        check(("reconciliation" in entry) == billing, "Billing loss requires exact reconciliation evidence")
        check(type(audit["schema_version"]) is int and audit["schema_version"] == 1
              and audit["kind"] == "confirmed_response_lost"
              and audit["reason"] in {"normalization_failure_response_not_retained", "billing_metadata_unavailable_response_not_retained"}
              and audit["provider_content_recovery"] == "operator_attests_unavailable"
              and audit["response_retained"] is False and audit["replay_eligible"] is False
              and audit["replacement_authorized"] is True, "Abandoned audit cannot claim recovered or replayable output")
        for key in ("call_id", "reserved_micros", "charged_micros"):
            nonnegative_int(audit[key], "abandoned " + key)
        lost_id = audit["call_id"]
        check(lost_id > 0 and lost_id not in lost_ids and lost_id not in retained_ids, "Reused or replayed abandoned call ID")
        check(audit["reserved_micros"] > 0 and audit["charged_micros"] <= audit["reserved_micros"],
              "Invalid abandoned confirmed cost")
        lost_ids.add(lost_id)
        ordered_lost_ids.append(lost_id)
        audit_id = digest(audit["source_audit_sha256"])
        check(audit_id not in audit_ids, "Reused source abandonment audit")
        audit_ids.add(audit_id)
        check(entry["audit"]["path"] == f"lineage/abandoned/{lost_id:06d}.audit.json"
              and entry["receipt_checkpoint"]["path"] == f"lineage/abandoned/{lost_id:06d}.receipt.json",
              "Noncanonical abandoned evidence path")
        check(digest(audit["receipt_checkpoint_sha256"]) == entry["receipt_checkpoint"]["sha256"],
              "Abandoned checkpoint hash differs from original audit")
        timestamp(audit["decided_at"])
        receipt = audit["receipt"]
        check(isinstance(receipt, dict) and set(receipt) == {"generation_id", "response_sha256", "request_sha256", "recorded_at"},
              "Invalid abandoned receipt projection")
        generation = receipt["generation_id"]
        check(isinstance(generation, str) and re.fullmatch(r"gen-[A-Za-z0-9_-]{1,200}", generation)
              and generation not in retained_receipts and generation not in lost_receipts,
              "Reused or replayed abandoned provider receipt")
        lost_receipts.add(generation)
        digest(receipt["response_sha256"])
        check(digest(audit["request_sha256"]) == digest(receipt["request_sha256"]),
              "Abandoned request differs from original receipt")
        timestamp(receipt["recorded_at"])
        check(datetime.fromisoformat(receipt["recorded_at"].replace("Z", "+00:00"))
              <= datetime.fromisoformat(audit["decided_at"].replace("Z", "+00:00")), "Abandonment predates original receipt")
        checkpoint_fields = {"schema_version", "call_id", "recorded_at", "generation_id", "request_sha256",
                             "response_sha256", "model_requested", "usage", "response_retained", "replay_eligible"}
        check(isinstance(checkpoint, dict) and set(checkpoint) == checkpoint_fields
              and type(checkpoint["schema_version"]) is int and checkpoint["schema_version"] == 1
              and type(checkpoint["call_id"]) is int and checkpoint["call_id"] == lost_id
              and checkpoint["response_retained"] is False and checkpoint["replay_eligible"] is False
              and all(exact_value(checkpoint[key], value) for key, value in receipt.items())
              and checkpoint["model_requested"] == audit["model"], "Abandoned receipt checkpoint differs from audit")
        usage = checkpoint["usage"]
        check(isinstance(usage, dict) and set(usage) == {"prompt_tokens", "completion_tokens", "total_tokens", "cost_usd"},
              "Abandoned receipt usage missing")
        for key in ("prompt_tokens", "completion_tokens", "total_tokens"):
            nonnegative_int(usage[key], "abandoned " + key)
        check(usage["total_tokens"] == usage["prompt_tokens"] + usage["completion_tokens"], "Abandoned receipt tokens differ")
        check(decimal_cost(usage["cost_usd"]) <= Decimal(audit["charged_micros"]) / Decimal(1000000),
              "Abandoned confirmed cost undercounts original receipt")
        if billing:
            check(entry["reconciliation"]["path"] == f"lineage/abandoned/{lost_id:06d}.reconciliation.json",
                  "Noncanonical billing reconciliation path")
            validate_billing_reconciliation(loaded["reconciliation"], audit, checkpoint, entry["receipt_checkpoint"]["sha256"])
        source = audit["source"]
        check(isinstance(source, dict) and set(source) == {"run_id", "case_id", "sequence", "inventory_sha256", "request_sha256", "request_body_sha256"},
              "Invalid abandoned source frontier")
        check(source["run_id"] == manifest["run_id"] and source["case_id"] in annotations
              and type(source["sequence"]) is int and source["sequence"] > 0, "Abandoned source identity differs")
        for key in ("inventory_sha256", "request_sha256", "request_body_sha256"):
            digest(source[key])
        frontier = {key: source[key] for key in ("request_sha256", "request_body_sha256")}
        check(any(level["next_requests"].get(source["case_id"]) == frontier
                  and len(level["grouped"].get(source["case_id"], [])) + 1 == source["sequence"] for level in levels),
              "Abandoned source is not a twice-replayed historical frontier")
        replacement = entry["replacement"]
        check(isinstance(replacement, dict) and set(replacement) == {"case_id", "sequence", "path", "sha256", "call_id"},
              "Invalid abandoned replacement reference")
        actual = observed.get(replacement["path"])
        check(actual is not None and all(exact_value(actual[key], value) for key, value in replacement.items()),
              "Abandoned replacement does not identify the retained call")
        check(actual["call_id"] > lost_id and actual["call_id"] not in replacements,
              "Abandoned replacement must be a distinct later physical call")
        check(all(exact_value(actual[key], source[key]) for key in ("case_id", "sequence", "request_sha256", "request_body_sha256"))
              and actual["model"] == audit["model"], "Abandoned replacement request differs from source frontier")
        replacements.add(actual["call_id"])
        expected_annotations[source["case_id"]].append(lost_id)
        charged_total += audit["charged_micros"]
    check(ordered_lost_ids == sorted(lost_ids), "Abandoned calls must follow physical call order")
    check(exact_value(annotations, {key: sorted(value) for key, value in expected_annotations.items()}),
          "Case abandoned-call annotations differ from explicit dispositions")
    return {"abandoned_paid_calls": len(entries), "abandoned_charged_micros": charged_total}


def validate_recorded(root, manifest, prototype=None, compare=None):
    check(manifest.get("schema_version") == 2 and manifest.get("equivalence_claim") is False,
          "Invalid recorded manifest schema or unsupported equivalence claim")
    inventory(manifest["cases"])
    no_secrets(manifest)
    run_id = manifest["run_id"]
    check(isinstance(run_id, str) and re.fullmatch(r"[A-Za-z0-9_-]{1,100}", run_id), "Invalid recording run ID")
    timestamp(manifest["captured_at"])
    versions = manifest["versions"]
    check(set(versions) == set(VERSION_HASHES) | {"normalization"}
          and versions["normalization"] == "contacts-v1", "Missing or unsupported version metadata")
    artifacts = manifest["artifacts"]
    check(set(artifacts) == {"baseline_manifest", "source_fingerprints", "config", "prompts", "harness"}, "Missing required version artifacts")
    used = {"manifest.json"}
    payloads = {}
    for name, reference in artifacts.items():
        path = safe_path(root, reference["path"])
        check(reference["path"] not in used, "Repeated artifact path")
        used.add(reference["path"])
        check(sha(path) == digest(reference["sha256"]), "Version artifact checksum mismatch")
        payloads[name] = read(path)
        no_secrets(payloads[name])
    check(artifacts["baseline_manifest"]["sha256"] == BASELINE_MANIFEST_SHA256, "Baseline inventory differs from T-01")
    check(artifacts["source_fingerprints"]["sha256"] == SOURCE_FINGERPRINT_SHA256, "Source fingerprint inventory differs from T-01")
    for version_key, artifact in VERSION_HASHES.items():
        check(digest(versions[version_key]) == artifacts[artifact]["sha256"], "Version digest differs from artifact")
    fingerprints = payloads["source_fingerprints"]
    prompt_hashes = {key: value for key, value in fingerprints.items() if key.startswith("resources/views/prompts/")}
    check(payloads["prompts"] == prompt_hashes, "Prompt versions differ from complete donor prompt inventory")
    check(isinstance(payloads["config"], dict) and payloads["config"], "Runtime config snapshot missing")
    harness_hashing, harness_files = parse_harness(payloads["harness"])
    check(REQUIRED_HARNESS <= set(harness_files), "Incomplete recorder harness version inventory")
    if "abandoned_calls" in manifest:
        check("tools/baseline/orphan_ledger.py" in harness_files, "Missing orphan accounting harness version")
        if any(isinstance(entry, dict) and "reconciliation" in entry for entry in manifest["abandoned_calls"]):
            check("tools/baseline/reconcile.py" in harness_files, "Missing billing reconciliation harness version")
    repository = HARNESS_ROOT
    for name, expected in harness_files.items():
        check(harness_source_sha(safe_path(repository, name), harness_hashing) == digest(expected),
              "Harness version differs from current code")
    if prototype:
        for name, expected in fingerprints.items():
            check(sha(safe_path(prototype, name)) == expected, "Donor source fingerprint mismatch")
    resume = validate_resume(root, manifest, payloads, used)
    receipt_ids = set()
    observed_calls, annotations = {}, {}
    total_calls, total_tokens, total_cost = 0, 0, Decimal(0)
    for item in manifest["cases"]:
        check(item.get("provenance") == "recorded", "Full recorded baseline requires 41 recorded cases")
        file = safe_path(root, item["fixture"])
        check(item["fixture"] not in used, "Repeated case fixture path")
        used.add(item["fixture"])
        check(sha(file) == digest(item["fixture_sha256"]), "Case fixture checksum mismatch")
        case = read(file)
        no_secrets(case)
        check(case.get("schema_version") == 2 and case.get("case_id") == item["id"]
              and case.get("kind") == item["kind"] and case.get("provenance") == "recorded", "Case fixture identity differs")
        check(case["versions"] == versions, "Case version metadata differs")
        capture = case["capture"]
        annotations[case["case_id"]] = capture.get("replacement_for_call_ids", [])
        check(capture["run_id"] == run_id and capture["method"] in ("live", "import")
              and capture["provider"] == "openrouter", "Invalid recorded capture provenance")
        timestamp(capture["captured_at"])
        check(capture.get("inputs_pseudonymized") is True and capture.get("byte_identical_persona_inputs") is False,
              "Contact normalization limits must be explicit")
        check(capture["seed"] == {"runner": 20261031, "requested": None, "provider_support": "not_requested"},
              "Unsupported seed claim: runner seed does not seed the provider")
        execution = case["execution"]
        scheduler = item["id"] in ("manual.followup_20h", "manual.followup_40h")
        layer = "donor_orchestrator_setup_and_scheduler" if scheduler else ("donor_orchestrator_persona" if item["kind"] == "persona" else "donor_orchestrator_scripted")
        check(execution["layer"] == item["execution_layer"] == layer, "Execution layer differs from case type")
        check(execution["input_provenance"] == ("donor_persona" if item["kind"] == "persona" else "authored_script"), "Input provenance differs")
        check(isinstance(execution["scenario"], dict) and execution["scenario"].get("case_id") == item["id"], "Scenario identity missing")
        check(digest_value(execution["scenario"]) == digest(execution["scenario_sha256"]), "Scenario checksum mismatch")
        trace = case["trace"]
        check(isinstance(trace, (dict, list)) and trace, "Empty observed execution trace")
        if scheduler:
            hours = [19.99, 20] if item["id"] == "manual.followup_20h" else [20, 39.99, 40, 79.99, 80]
            expected_followups = [0, 1] if item["id"] == "manual.followup_20h" else [1, 1, 2, 2, 2]
            check(execution["scenario"].get("scheduler_hours") == hours, "Scheduler scenario boundaries differ")
            check(isinstance(trace, dict) and isinstance(trace.get("setup"), list) and trace["setup"]
                  and isinstance(trace.get("scheduler"), list), "Scheduler case requires actual setup and timer observations")
            observations = trace["scheduler"]
            check([point.get("elapsed_hours") for point in observations] == hours
                  and [point.get("followups_sent") for point in observations] == expected_followups
                  and all(type(point.get("followups_sent")) is int for point in observations), "Scheduler timing behavior drift")
            if item["id"] == "manual.followup_40h":
                check(observations[-1].get("outcome") == "stale", "Scheduler stale boundary drift")
        replay = case["replay"]
        trace_hash = digest_value(trace)
        calls = case["llm_calls"]
        check(isinstance(calls, list) and calls, "Recorded case has no real LLM calls")
        sequences = list(range(1, len(calls) + 1))
        check([call["sequence"] for call in calls] == sequences and all(type(call["sequence"]) is int for call in calls), "LLM call sequence has gaps, duplicates or wrong order")
        check(type(replay["passes"]) is int and replay["passes"] == 2
              and replay["trace_sha256"] == trace_hash
              and replay["run_trace_sha256"] == [trace_hash, trace_hash]
              and exact_value(replay["consumed_sequences"], [sequences, sequences])
              and type(replay["network_calls"]) is int and replay["network_calls"] == 0,
              "Two identical, fully exhausted offline replay receipts are required")
        validate_resume_case(resume, case)
        for call in calls:
            check(call["phase"] == ("setup" if scheduler else "dialogue"), "LLM call phase differs from execution layer")
            path = safe_path(root, call["path"])
            check(call["path"] not in used and path.name == f"{call['sequence']:04d}.json", "Repeated or noncanonical LLM call path")
            used.add(call["path"])
            check(sha(path) == digest(call["sha256"]), "LLM call file checksum mismatch")
            record = read(path)
            no_secrets(record)
            tokens, cost = validate_call(record, case, call, run_id, receipt_ids)
            validate_resume_call(resume, case, call, record)
            if "abandoned_calls" in manifest:
                observed_calls[call["path"]] = call_audit_summary(case, call, record)
            total_calls += 1
            total_tokens += tokens
            total_cost += cost
    check(all(level["tail_checked"] == set(level["next_requests"]) for level in resume), "Resume prefix must have an observed live tail")
    abandoned = validate_abandoned_calls(root, manifest, resume, used, observed_calls, annotations)
    expected_coverage = {"total_cases": 41, "personas": 29, "manual": 12, "recorded_real_llm": 41, "synthetic": 0, "missing": 0}
    check(exact_value(manifest["coverage"], expected_coverage), "Coverage metadata differs from recorded evidence")
    check(set(files(root)) == used, "Recorded bundle has orphan or undeclared files")
    if compare:
        check(files(root) == files(compare), "Recorded comparison differs")
    return {"result": "passed", **expected_coverage, **abandoned, "llm_calls": total_calls, "tokens": total_tokens,
            "provider_cost_usd": str(total_cost), "offline_replay_passes": 2, "equivalence_claim": False,
            "provenance_verification": "structural_integrity_not_independent_provider_attestation"}


def validate(root, prototype=None, compare=None, require_recorded=False):
    root = Path(root)
    manifest = read(safe_path(root, "manifest.json"))
    check(isinstance(manifest, dict) and type(manifest.get("schema_version")) is int, "Invalid manifest schema")
    if manifest.get("schema_version") == 2:
        return validate_recorded(root, manifest, prototype, compare)
    return validate_legacy(root, prototype, compare, require_recorded)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--fixtures", type=Path, default=Path("tests/fixtures/prototype"))
    parser.add_argument("--prototype", type=Path)
    parser.add_argument("--compare", type=Path)
    parser.add_argument("--require-recorded", action="store_true")
    args = parser.parse_args()
    try:
        print(json.dumps(validate(args.fixtures, args.prototype, args.compare, args.require_recorded), ensure_ascii=False))
    except (OSError, ValueError, KeyError, TypeError, IndexError, UnicodeError, AttributeError, RecursionError) as error:
        message = str(error) if isinstance(error, ValueError) else "Evidence file or schema is unavailable or invalid"
        print(json.dumps({"result": "failed", "error": message}, ensure_ascii=False))
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
