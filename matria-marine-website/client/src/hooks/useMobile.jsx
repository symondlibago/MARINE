import * as React from "react";

const MOBILE_BREAKPOINT = 768;

export function useIsMobile() {
  // Was `useState<boolean | undefined>(undefined)` — a TypeScript annotation in
  // a .jsx file, which is not JavaScript. Read as plain JS it is a chain of
  // comparisons against an undeclared `boolean`, which is why the linter found
  // it the moment it was switched on.
  const [isMobile, setIsMobile] = React.useState(undefined);

  React.useEffect(() => {
    const mql = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`);
    const onChange = () => {
      setIsMobile(window.innerWidth < MOBILE_BREAKPOINT);
    };
    mql.addEventListener("change", onChange);
    setIsMobile(window.innerWidth < MOBILE_BREAKPOINT);
    return () => mql.removeEventListener("change", onChange);
  }, []);

  return !!isMobile;
}
