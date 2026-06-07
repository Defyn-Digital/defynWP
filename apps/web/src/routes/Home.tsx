import { Navigate } from 'react-router-dom';

/**
 * The root authenticated route. P2.5: post-login landing is /overview.
 */
export default function Home() {
  return <Navigate to="/overview" replace />;
}
