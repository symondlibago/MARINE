/**
 * Where an account belongs once it has signed in.
 *
 * The server says which kind of account it is — `scope` on the login and
 * /api/user payloads — and this turns that into a landing page. A vessel's
 * master and cook reach the provision module and nothing else, so sending them
 * to the procurement portal shows them a refusal on their own system.
 *
 * One function, because the decision is made in three places: after the login
 * modal, when the portal is opened directly, and when an old bookmark is
 * followed.
 */
export function homeFor(user) {
  if (user?.scope === "vessel") {
    return user.vessel_id ? `/inventory/v/${user.vessel_id}` : "/inventory";
  }

  return "/portal";
}
