import { createContext, useContext } from "react";

/**
 * The vessel on screen, and what the signed-in account may do with her.
 *
 * Both come from one request, the one the shell already waits on, so no screen
 * below fetches the vessel again and none of them can be looking at a
 * different one.
 *
 * The permissions come from the server — the same definition the API refuses
 * requests by (App\Support\Inventory\VesselAccess), not a second copy of the
 * rules kept here. A button this says no to is not drawn; a button it says yes
 * to would be accepted if pressed. Nothing in the module decides access by
 * matching role strings of its own.
 *
 * `money` is the odd one out, because it hides a column rather than refusing a
 * request. It is not what stops the cook reading a price — the API has already
 * taken the figures out of every payload before they reach him. It is so the
 * screen does not draw a Unit price column full of dashes for somebody who was
 * never going to see one.
 */
const AccessContext = createContext({
  vessel: null,
  scope: null,     // "office" — the whole fleet | "vessel" — this ship only
  rank: null,      // "Master" | "Cook" | null for the office
  office: false,   // buys, adds ships, manages accounts
  master: false,   // sets how the ship is provisioned (true for the office too)
  money: false,    // is shown prices, budgets and what an order comes to
});

export function AccessProvider({ value, children }) {
  return <AccessContext.Provider value={value}>{children}</AccessContext.Provider>;
}

export function useAccess() {
  return useContext(AccessContext);
}

/**
 * Whether a screen gated on a duty is open to this account.
 *
 * `needs` is undefined for the ship's own work — counting, withdrawing,
 * receiving, asking — which is almost everything and open to the cook.
 */
export function allows(access, needs) {
  if (needs === "office") return !!access?.office;
  if (needs === "master") return !!access?.master;
  return true;
}
