/**
 * Parses inline citation tokens out of an LLM response so the chat bubble
 * displays clean prose while the badge modal can still link each source.
 *
 * The system prompt instructs the model to emit `(PMID:12345)` or
 * `(USDA FDC ID: 173410)` or `(Open Food Facts: 3017620422003)` as silent
 * evidence markers. This module:
 *   1. extracts each unique citation (type + key + resolvable URL),
 *   2. strips the entire parenthesised citation group — including groups
 *      containing multiple comma/semicolon-separated keys — from the
 *      displayed content,
 *   3. collapses the whitespace the removal left behind so the prose
 *      reads naturally.
 */

export type ParsedCitation = {
  type: 'pubmed' | 'usda' | 'open_food_facts';
  key: string;
  url: string;
};

export type ParsedContent = {
  cleaned: string;
  citations: ParsedCitation[];
};

// Matches a citation group in one paren. Can contain one or more keys
// separated by comma or semicolon, e.g.
//   (PMID:123)
//   (PMID:123, PMID:456)
//   (PMID:123; FDC ID: 789)
//   (USDA FDC ID: 173410)
// Deliberately strict about what can sit inside the paren so we don't
// accidentally strip things like "(3 grams)" or "(see later)".
const CITATION_GROUP = /\s*\(\s*(?:(?:USDA\s+)?FDC\s*ID\s*[:]\s*\d+|PMID\s*[:]\s*\d+|Open\s+Food\s+Facts\s*[:]\s*\d+)(?:\s*[,;]\s*(?:(?:USDA\s+)?FDC\s*ID\s*[:]\s*\d+|PMID\s*[:]\s*\d+|Open\s+Food\s+Facts\s*[:]\s*\d+))*\s*\)/gi;

const PMID_RE = /PMID\s*[:]\s*(\d+)/gi;
const FDC_RE = /(?:USDA\s+)?FDC\s*ID\s*[:]\s*(\d+)/gi;
const OFF_RE = /Open\s+Food\s+Facts\s*[:]\s*(\d+)/gi;

function pubmedUrl(pmid: string): string {
  return `https://pubmed.ncbi.nlm.nih.gov/${pmid}/`;
}

function usdaUrl(fdcId: string): string {
  return `https://fdc.nal.usda.gov/fdc-app.html#/?query=${fdcId}`;
}

function offUrl(barcode: string): string {
  return `https://world.openfoodfacts.org/product/${barcode}`;
}

export function parseContent(raw: string): ParsedContent {
  if (!raw) return { cleaned: '', citations: [] };

  const citations: ParsedCitation[] = [];
  const seen = new Set<string>();
  const push = (c: ParsedCitation) => {
    const id = `${c.type}|${c.key}`;
    if (seen.has(id)) return;
    seen.add(id);
    citations.push(c);
  };

  for (const m of raw.matchAll(PMID_RE)) {
    push({ type: 'pubmed', key: `PMID:${m[1]}`, url: pubmedUrl(m[1]) });
  }
  for (const m of raw.matchAll(FDC_RE)) {
    push({ type: 'usda', key: `FDC ID:${m[1]}`, url: usdaUrl(m[1]) });
  }
  for (const m of raw.matchAll(OFF_RE)) {
    push({ type: 'open_food_facts', key: `OFF:${m[1]}`, url: offUrl(m[1]) });
  }

  // Strip the parenthesised citation groups, then tidy the whitespace
  // (avoid "foo  ." from stripping "foo (PMID:X)." → "foo ." → "foo.").
  const stripped = raw.replace(CITATION_GROUP, '');
  const cleaned = stripped
    .replace(/\s+([.,;:!?])/g, '$1')
    .replace(/\s+/g, ' ')
    .trim();

  return { cleaned, citations };
}

export function labelForCitation(c: ParsedCitation): string {
  switch (c.type) {
    case 'pubmed':
      return c.key;
    case 'usda':
      return `USDA ${c.key}`;
    case 'open_food_facts':
      return `Open Food Facts ${c.key.replace('OFF:', '')}`;
  }
}

export function sourceNameForCitation(c: ParsedCitation): string {
  switch (c.type) {
    case 'pubmed':
      return 'PubMed';
    case 'usda':
      return 'USDA FoodData Central';
    case 'open_food_facts':
      return 'Open Food Facts';
  }
}
