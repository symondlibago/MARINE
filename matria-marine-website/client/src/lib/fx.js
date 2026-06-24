// Live foreign-exchange rates.
//
// The rate is fetched by OUR backend (see FxController), not the browser, so it
// keeps working even when the machine intercepts outbound TLS. The response is
// { base, rates, date } where rates[X] = units of X per 1 base. We also cache it
// per base-currency per day in localStorage to avoid repeat calls.

import { fxAPI } from "@/pages/api";

const memo = new Map(); // base -> { base, rates, date }

const dayKey = (base) => `fx:${base}:${new Date().toISOString().slice(0, 10)}`;

/**
 * Fetch (or reuse cached) rates for a base currency.
 * Returns { base, rates, date } where rates[X] = units of X per 1 base.
 */
export async function fetchRates(base) {
  base = (base || "USD").toUpperCase();

  if (memo.has(base)) return memo.get(base);

  try {
    const cached = JSON.parse(localStorage.getItem(dayKey(base)) || "null");
    if (cached?.rates) {
      memo.set(base, cached);
      return cached;
    }
  } catch {
    /* ignore corrupt cache */
  }

  const res = await fxAPI.rates(base);
  const data = res.data?.data;
  if (!data?.rates) {
    throw new Error("FX lookup failed");
  }

  const out = { base, rates: data.rates, date: data.date || "" };
  memo.set(base, out);
  try {
    localStorage.setItem(dayKey(base), JSON.stringify(out));
  } catch {
    /* storage full / disabled — fine, keep the in-memory copy */
  }
  return out;
}

/**
 * How many `base` units equal 1 unit of `from` — i.e. the exchange rate we store
 * on a quote (vendor unit_cost in `from` × this = cost in base currency).
 */
export function rateToBase(ratesObj, from) {
  if (!ratesObj?.rates) return null;
  from = (from || "").toUpperCase();
  if (from === ratesObj.base) return 1;
  const perBase = Number(ratesObj.rates[from]); // units of `from` per 1 base
  if (!perBase || perBase <= 0) return null;
  return 1 / perBase;
}
