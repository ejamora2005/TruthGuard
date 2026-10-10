import { useCallback, useEffect, useState } from 'react';
import { fetchAdminJson } from '../lib/admin-api';

export function useAdminQuery(endpoint, initialData) {
    const [data, setData] = useState(initialData);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const load = useCallback(async (completion = null) => {
        const done = typeof completion === 'function' ? completion : null;
        let succeeded = false;
        setLoading(true);
        setError('');

        try {
            const payload = await fetchAdminJson(endpoint);
            setData(payload);
            succeeded = true;
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Something went wrong while loading this page.');
        } finally {
            setLoading(false);
            done?.(succeeded);
        }
    }, [endpoint]);

    useEffect(() => {
        const done = window.TruthGuardLoader?.begin();
        load(done);
        return () => done?.(false);
    }, [load]);

    return { data, loading, error, load };
}
