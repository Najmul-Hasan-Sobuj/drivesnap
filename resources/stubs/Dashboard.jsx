import { Head } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { mountDashboard } from '__IMPORT__';
import '../../drivesnap/dashboard.css';
export default function Dashboard({ drivesnap }) {
    const root = useRef(null);
    useEffect(() => mountDashboard(root.current, drivesnap), []);
    return (
        <>
            <Head title="DriveSnap backups" />
            <div ref={root} className="drivesnap">
                <p role="status">Loading backup dashboard…</p>
            </div>
        </>
    );
}
