import {
  datesAndTimes,
  properNames,
  significantNumbers,
  normalizeSpace,
} from "./utils.js";

const redPatterns = [
  /accusa|denuncia contro|pedofil|violenza sessuale|stupro|omicidio|suicid|dati sanitari|cartella clinica/i,
  /minore identificabile|bambin[oa]\s+[A-Z][a-z]+\s+[A-Z][a-z]+/i,
  /ammazz|uccid|ti trovo|indirizzo di casa|numero di telefono privato|codice fiscale/i,
  /negro|frocio|zingaro|puttana|handicappato/i,
];
const yellowPatterns = [
  /arrest|indagat|processo|tribunale|aggress|furto|rapina|incidente grave|persona scomparsa/i,
  /truff|ladro|corrott|abusiv|molest|droga|arma|rissa|discrimin/i,
  /\b(?:3\d{2}[ .-]?\d{6,7}|(?:\+39[ .-]?)?0\d{1,3}[ .-]?\d{5,8})\b/i,
];

export function riskGate(record, trust = 50) {
  const text = `${record.title || ""} ${record.text || ""}`;
  if (redPatterns.some((r) => r.test(text)))
    return { level: "RED", score: 100, reason: "sensitive_or_accusatory" };
  if (yellowPatterns.some((r) => r.test(text)) || trust < 40)
    return { level: "YELLOW", score: 60, reason: "ambiguous_or_sensitive" };
  return { level: "GREEN", score: 10, reason: "low_risk" };
}

// Automated checks only prioritise the queue: UGC always requires a person.
export function ugcModerationGate(record) {
  const risk = riskGate(record, 25);
  return { ...risk, decision: "HOLD_FOR_REVIEW" };
}

export function valueGate(record) {
  const title = normalizeSpace(record.title),
    text = normalizeSpace(record.text);
  const hasArea = Number(record.area_id || 0) > 0;
  const hasWhen = Boolean(
    record.original_date ||
    record.valid_from ||
    /\b(?:oggi|domani|luned[iì]|marted[iì]|mercoled[iì]|gioved[iì]|venerd[iì]|sabato|domenica|\d{1,2}[:.]\d{2}|\d{1,2}[\/-]\d{1,2})\b/i.test(
      text,
    ),
  );
  const hasWhere = Boolean(
    record.address ||
    record.venue ||
    /\b(?:via|viale|piazza|largo|municipio|quartiere|zona)\b/i.test(
      `${title} ${text}`,
    ),
  );
  const hasImpact =
    /chius|deviaz|interru|orari|servizio|evento|apertura|modifica|lavori|traffico|trasporto|scuola|raccolta|mercato|viabilit|accesso|prenot|gratuit|costo/i.test(
      `${title} ${text}`,
    );
  const substantive = title.length >= 12 && text.length >= 80;
  const pass = hasArea && substantive && hasImpact && (hasWhen || hasWhere);
  return {
    pass,
    reason: pass ? "local_utility" : "insufficient_local_utility",
    checks: { hasArea, substantive, hasImpact, hasWhen, hasWhere },
  };
}

export function factGate(source, generated) {
  const src = `${source.title || ""} ${source.text || ""}`;
  const out = `${generated.headline || ""} ${generated.summary || ""} ${generated.body || ""}`;
  const violations = [];
  const mustBeSubset = (label, extractor) => {
    const s = new Set(extractor(src));
    const g = new Set(extractor(out));
    for (const v of g) if (!s.has(v)) violations.push(`${label}:${v}`);
  };
  mustBeSubset("date_time", datesAndTimes);
  mustBeSubset("number", significantNumbers);
  const srcNames = properNames(src).map((v) => v.toLowerCase());
  for (const n of properNames(out))
    if (!srcNames.includes(n.toLowerCase()))
      violations.push(`proper_name:${n}`);
  if (
    generated.address &&
    !src.toLowerCase().includes(String(generated.address).toLowerCase())
  )
    violations.push(`address:${generated.address}`);
  return { pass: violations.length === 0, violations };
}
