import { Loader2 } from "lucide-react";

export function Spinner({ className = "h-5 w-5" }) {
  return <Loader2 className={`animate-spin ${className}`} />;
}

export function PageLoader({ label = "Loading…" }) {
  return (
    <div className="flex items-center justify-center gap-2 py-20 text-sm text-slate-400">
      <Loader2 className="h-5 w-5 animate-spin" /> {label}
    </div>
  );
}

/** Shimmer rows to drop inside a <tbody> while a table loads. */
export function TableSkeleton({ cols = 4, rows = 5 }) {
  return (
    <>
      {Array.from({ length: rows }).map((_, r) => (
        <tr key={r} className="border-b border-slate-100 last:border-0">
          {Array.from({ length: cols }).map((_, c) => (
            <td key={c} className="px-4 py-3.5">
              <div className="h-3.5 animate-pulse rounded bg-slate-100" style={{ width: `${40 + ((r + c) % 4) * 15}%` }} />
            </td>
          ))}
        </tr>
      ))}
    </>
  );
}
