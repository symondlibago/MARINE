/**
 * Payroll — API layer.
 *
 * Separate from the procurement API in pages/api.js and from the inventory one,
 * but it reuses that same axios instance on purpose: the base URL and the
 * auth-token interceptor stay defined in exactly one place, so all three
 * operations always talk to the same backend with the same login.
 */
import api from "@/pages/api";

const url = (path) => `/api/payroll${path}`;

export const payrollAPI = {
  // Employee master
  employees: (params = {}) => api.get(url("/employees"), { params }),
  createEmployee: (payload) => api.post(url("/employees"), payload),
  updateEmployee: (id, payload) => api.put(url(`/employees/${id}`), payload),
  removeEmployee: (id) => api.delete(url(`/employees/${id}`)),

  // Payroll months
  runs: () => api.get(url("/runs")),
  createRun: (payload) => api.post(url("/runs"), payload),
  run: (id) => api.get(url(`/runs/${id}`)),
  updateRun: (id, payload) => api.patch(url(`/runs/${id}`), payload),
  removeRun: (id) => api.delete(url(`/runs/${id}`)),

  addLine: (runId, employee_id) => api.post(url(`/runs/${runId}/lines`), { employee_id }),
  updateLine: (runId, lineId, payload) => api.patch(url(`/runs/${runId}/lines/${lineId}`), payload),
  removeLine: (runId, lineId) => api.delete(url(`/runs/${runId}/lines/${lineId}`)),

  finalise: (id) => api.post(url(`/runs/${id}/finalise`)),
  reopen: (id) => api.post(url(`/runs/${id}/reopen`)),

  // The two printed documents
  summaryPdf: (id) => api.get(url(`/runs/${id}/summary.pdf`), { responseType: "blob" }),
  payslipPdf: (runId, lineId) => api.get(url(`/runs/${runId}/lines/${lineId}/payslip.pdf`), { responseType: "blob" }),

  // Letterhead and the statutory figures on show
  settings: () => api.get(url("/settings")),
  saveSettings: (payload) => api.put(url("/settings"), payload),
};

export default payrollAPI;
