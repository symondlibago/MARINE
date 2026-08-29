import { useEffect, useState } from "react";

/**
 * The value, held back until typing stops.
 *
 * Search boxes that hit the server need this or every keystroke is a request,
 * and the replies can land out of order — you type "MMS-27", the answer for
 * "MMS-2" arrives last, and the list shows the wrong rows. The input itself
 * stays immediate; only what the query keys off is delayed.
 */
export function useDebounced(value, ms = 300) {
  const [settled, setSettled] = useState(value);

  useEffect(() => {
    const t = setTimeout(() => setSettled(value), ms);

    return () => clearTimeout(t);
  }, [value, ms]);

  return settled;
}
