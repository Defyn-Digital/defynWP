import { Navigate } from 'react-router-dom';

/**
 * The root authenticated route. Sends users to /sites; the welcome card
 * from F3b moves to either the sites list (post-F5) or a dedicated
 * dashboard page (post-F9 when activity log lands).
 */
export default function Home() {
  return <Navigate to="/sites" replace />;
}
