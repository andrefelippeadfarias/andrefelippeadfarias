#!/usr/bin/env python3
"""Validate a hotel/date matrix and create an offline capture plan (stdlib only).

The range end is an inclusive check-in date; checkout is exclusive. Explicit
stays retain their input order, followed by matching range dates in date order.
This helper never queries Booking.com and never fills in prices or availability.
"""

from __future__ import annotations

import argparse
import csv
from datetime import date, timedelta
import hashlib
import io
import json
from pathlib import Path
import re
import sys
from urllib.parse import urlsplit, urlunsplit


CSV_COLUMNS = [
    "job_id", "hotel_key", "hotel_name", "booking_url", "checkin", "checkout",
    "nights", "adults", "rooms", "children_ages", "currency", "status",
    "captured_at", "offer_id", "room_name", "rate_plan", "total_displayed",
    "total_all_in", "excluded_charges", "tax_status", "breakfast",
    "cancellation", "payment", "availability_reason", "source_url",
    "evidence_ref", "notes", "run_id", "price_raw", "price_basis",
    "discount_eligibility", "comparison_profile", "criteria_status",
    "confirmed_occupancy", "source_type",
]


class ConfigError(ValueError):
    """A configuration is invalid or cannot safely be written."""


def _object(value, label, required, optional=()):
    if not isinstance(value, dict):
        raise ConfigError(f"{label}: expected an object")
    missing = set(required) - value.keys()
    extra = value.keys() - set(required) - set(optional)
    if missing:
        raise ConfigError(f"{label}: missing fields: {', '.join(sorted(missing))}")
    if extra:
        raise ConfigError(f"{label}: unknown fields: {', '.join(sorted(extra))}")
    return value


def _integer(value, label, minimum, maximum=None):
    if type(value) is not int or value < minimum:
        raise ConfigError(f"{label}: expected an integer >= {minimum} (not a boolean)")
    if maximum is not None and value > maximum:
        raise ConfigError(f"{label}: expected an integer <= {maximum}")
    return value


def _string(value, label):
    if not isinstance(value, str) or not value.strip():
        raise ConfigError(f"{label}: expected a nonempty string")
    try:
        value.encode("utf-8")
    except UnicodeError as exc:
        raise ConfigError(f"{label}: invalid Unicode text") from exc
    return value.strip()


def _date(value, label):
    if not isinstance(value, str) or re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}", value) is None:
        raise ConfigError(f"{label}: expected a strict ISO date YYYY-MM-DD")
    try:
        return date.fromisoformat(value)
    except ValueError as exc:
        raise ConfigError(f"{label}: invalid calendar date {value!r}") from exc


def _booking_url(value, label):
    value = _string(value, label)
    try:
        parsed = urlsplit(value)
        valid = (
            parsed.scheme == "https"
            and parsed.hostname in {"booking.com", "www.booking.com"}
            and parsed.username is None
            and parsed.password is None
            and parsed.port is None
            and re.fullmatch(r"/hotel/[^\s]+\.html", parsed.path) is not None
            and not any(character.isspace() for character in value)
            and not any(ord(character) < 32 or ord(character) == 127 for character in value)
        )
    except ValueError as exc:
        raise ConfigError(f"{label}: invalid URL") from exc
    if not valid:
        raise ConfigError(
            f"{label}: expected an HTTPS Booking.com hotel URL, "
            "such as https://www.booking.com/hotel/br/example.html"
        )
    # Keep a stable property URL, without old stay parameters or session IDs.
    return urlunsplit(("https", parsed.hostname, parsed.path, "", ""))


def _unique_json_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ConfigError(f"JSON: duplicate field {key!r}")
        result[key] = value
    return result


def load_config(path):
    try:
        with Path(path).open("r", encoding="utf-8-sig") as stream:
            return json.load(stream, object_pairs_hook=_unique_json_object)
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise ConfigError(f"cannot read configuration: {exc}") from exc


def build_plan(config, today=None):
    """Return a validated plan, with no filesystem or network side effects."""
    as_of = date.today() if today is None else _date(today, "today")
    _object(
        config, "config", {"schema_version", "currency", "occupancy", "hotels"},
        {"stays", "range", "max_jobs"},
    )
    if type(config["schema_version"]) is not int or config["schema_version"] != 1:
        raise ConfigError("schema_version: expected integer 1")
    currency = config["currency"]
    if not isinstance(currency, str) or re.fullmatch(r"[A-Z]{3}", currency) is None:
        raise ConfigError("currency: expected exactly 3 uppercase ISO currency letters")
    occupancy_config = _object(
        config["occupancy"], "occupancy", {"adults", "rooms", "children_ages"},
    )
    ages = occupancy_config["children_ages"]
    if not isinstance(ages, list):
        raise ConfigError("occupancy.children_ages: expected a list")
    occupancy = {
        "adults": _integer(occupancy_config["adults"], "occupancy.adults", 1),
        "rooms": _integer(occupancy_config["rooms"], "occupancy.rooms", 1),
        "children_ages": [
            _integer(age, f"occupancy.children_ages[{index}]", 0, 17)
            for index, age in enumerate(ages)
        ],
    }
    max_jobs = _integer(config.get("max_jobs", 500), "max_jobs", 1)
    raw_hotels = config["hotels"]
    if not isinstance(raw_hotels, list) or not raw_hotels:
        raise ConfigError("hotels: expected a nonempty list")
    hotels = []
    hotel_keys = set()
    for index, raw_hotel in enumerate(raw_hotels):
        label = f"hotels[{index}]"
        _object(raw_hotel, label, {"hotel_key", "name", "booking_url"}, {"required_features"})
        key = _string(raw_hotel["hotel_key"], f"{label}.hotel_key")
        if key in hotel_keys:
            raise ConfigError(f"{label}.hotel_key: duplicate key {key!r}")
        hotel_keys.add(key)
        features = raw_hotel.get("required_features", [])
        if not isinstance(features, list):
            raise ConfigError(f"{label}.required_features: expected a list")
        hotels.append({
            "hotel_key": key,
            "hotel_name": _string(raw_hotel["name"], f"{label}.name"),
            "booking_url": _booking_url(raw_hotel["booking_url"], f"{label}.booking_url"),
            "required_features": [
                _string(feature, f"{label}.required_features[{feature_index}]")
                for feature_index, feature in enumerate(features)
            ],
        })

    periods = []
    seen_periods = set()

    def add_period(checkin, checkout, label):
        if checkin < as_of or checkout < as_of:
            raise ConfigError(f"{label}: dates before as_of_date {as_of.isoformat()} are not allowed")
        if checkout <= checkin:
            raise ConfigError(f"{label}: checkout must be after checkin (checkout is exclusive)")
        pair = (checkin, checkout)
        if pair not in seen_periods:
            seen_periods.add(pair)
            periods.append(pair)
            if len(periods) * len(hotels) > max_jobs:
                raise ConfigError(f"planned matrix exceeds max_jobs={max_jobs}; reduce hotels or dates")

    if "stays" in config:
        stays = config["stays"]
        if not isinstance(stays, list) or not stays:
            raise ConfigError("stays: expected a nonempty list when provided")
        for index, stay in enumerate(stays):
            label = f"stays[{index}]"
            _object(stay, label, {"checkin", "checkout"})
            add_period(
                _date(stay["checkin"], f"{label}.checkin"),
                _date(stay["checkout"], f"{label}.checkout"), label,
            )
    if "range" in config:
        date_range = _object(
            config["range"], "range", {"start", "end", "nights"}, {"step_days", "weekdays"},
        )
        start = _date(date_range["start"], "range.start")
        end = _date(date_range["end"], "range.end")
        if start < as_of or end < as_of:
            raise ConfigError(f"range: dates before as_of_date {as_of.isoformat()} are not allowed")
        if end < start:
            raise ConfigError("range: end must be on or after start (inclusive check-in dates)")
        nights = _integer(date_range["nights"], "range.nights", 1)
        step = _integer(date_range.get("step_days", 1), "range.step_days", 1)
        weekdays = date_range.get("weekdays", list(range(7)))
        if not isinstance(weekdays, list) or not weekdays:
            raise ConfigError("range.weekdays: expected a nonempty list of integers from 0 to 6")
        weekdays = {
            _integer(day, f"range.weekdays[{index}]", 0, 6)
            for index, day in enumerate(weekdays)
        }
        current = start
        while current <= end:
            if current.weekday() in weekdays:
                try:
                    checkout = current + timedelta(days=nights)
                except (OverflowError, ValueError) as exc:
                    raise ConfigError("range.nights: checkout would exceed supported calendar dates") from exc
                add_period(current, checkout, "range")
            remaining_days = (end - current).days
            if step > remaining_days:
                break
            current += timedelta(days=step)
    if not periods:
        raise ConfigError("provide stays or a range that selects at least one check-in date")

    jobs = []
    for hotel in hotels:
        for checkin, checkout in periods:
            parameters = {
                **hotel,
                "checkin": checkin.isoformat(),
                "checkout": checkout.isoformat(),
                "nights": (checkout - checkin).days,
                "occupancy": {**occupancy, "children_ages": list(occupancy["children_ages"])},
                "currency": currency,
            }
            canonical = json.dumps(parameters, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
            job_id = hashlib.sha256(canonical.encode("utf-8")).hexdigest()[:20]
            jobs.append({"job_id": job_id, **parameters})
    return {
        "schema_version": 1,
        "as_of_date": as_of.isoformat(),
        "currency": currency,
        "occupancy": occupancy,
        "max_jobs": max_jobs,
        "planned_jobs": len(jobs),
        "jobs": jobs,
    }


def _csv_text(plan):
    stream = io.StringIO(newline="")
    writer = csv.DictWriter(stream, fieldnames=CSV_COLUMNS)
    writer.writeheader()
    for job in plan["jobs"]:
        row = {key: job[key] for key in (
            "job_id", "hotel_key", "hotel_name", "booking_url", "checkin",
            "checkout", "nights", "currency",
        )}
        row.update(job["occupancy"])
        row["children_ages"] = json.dumps(row["children_ages"], separators=(",", ":"))
        row["status"] = "pending"
        writer.writerow(row)
    return stream.getvalue()


def write_outputs(plan, output_dir):
    """Create both new files exclusively, removing only our files on I/O errors."""
    payloads = (
        ("plan.json", (json.dumps(plan, ensure_ascii=False, indent=2) + "\n").encode("utf-8")),
        ("capture-template.csv", _csv_text(plan).encode("utf-8-sig")),
    )
    output_dir = Path(output_dir)
    paths = [output_dir / name for name, _ in payloads]
    if any(path.exists() or path.is_symlink() for path in paths):
        raise ConfigError("output already contains plan.json or capture-template.csv; use a new output folder")
    created = []
    try:
        output_dir.mkdir(parents=True, exist_ok=True)
        for (name, payload), path in zip(payloads, paths):
            with path.open("xb") as stream:
                created.append(path)
                stream.write(payload)
    except OSError as exc:
        for path in created:
            try:
                path.unlink()
            except OSError:
                pass
        raise ConfigError(f"cannot safely create output files: {exc}") from exc
    return paths


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", required=True, help="UTF-8 JSON matrix configuration")
    parser.add_argument("--output", required=True, help="folder for new plan.json and capture-template.csv")
    parser.add_argument("--today", help="strict YYYY-MM-DD override for reproducible validation")
    args = parser.parse_args(argv)
    try:
        plan = build_plan(load_config(args.config), today=args.today)
        paths = write_outputs(plan, args.output)
    except (ConfigError, OSError) as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 2
    print(f"Created {plan['planned_jobs']} pending jobs as of {plan['as_of_date']}.")
    for path in paths:
        print(path.resolve())
    return 0


if __name__ == "__main__":
    sys.exit(main())
