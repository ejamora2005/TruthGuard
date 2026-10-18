"""Exercise checkpoint download failures with tiny fixtures and no network calls."""
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    'install_ai_origin', ROOT / 'automation/deepfake-models/install_ai_origin.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class Response(io.BytesIO):
    def __init__(self, data, status=200, headers=None):
        super().__init__(data)
        self.status = status
        self.headers = headers or {}


class ImageOriginInstallerTests(unittest.TestCase):
    def setUp(self):
        output = ROOT / 'output/validation'
        output.mkdir(parents=True, exist_ok=True)
        temporary = tempfile.TemporaryDirectory(prefix='ai-origin-installer-', dir=output)
        self.directory = Path(temporary.name).resolve()
        self.assertEqual(self.directory.parent, output.resolve())
        self.addCleanup(temporary.cleanup)
        self.weights = b'fixture checkpoint bytes'
        expected = hashlib.sha256(self.weights).hexdigest()
        configurations = {}
        for name in ('config.json', 'preprocessor_config.json', 'siglip_config.json'):
            (self.directory / name).write_bytes(b'{}')
            configurations[name] = hashlib.sha256(b'{}').hexdigest()
        (self.directory / 'source.json').write_text(json.dumps({
            'revision': installer.REVISION, 'sha256': expected,
            'configuration_sha256': configurations}), encoding='utf-8')
        for name, value in [('DIRECTORY', self.directory), ('SIZE', len(self.weights)), ('SHA256', expected)]:
            patcher = patch.object(installer, name, value)
            patcher.start()
            self.addCleanup(patcher.stop)
        self.target = self.directory / 'pytorch_model.pt'
        self.partial = self.directory / 'pytorch_model.pt.tmp'
        network = patch.object(installer, 'urlopen', side_effect=AssertionError('Unexpected network request'))
        self.network = network.start()
        self.addCleanup(network.stop)
        quiet = patch('sys.stdout', new_callable=io.StringIO)
        quiet.start()
        self.addCleanup(quiet.stop)

    def response(self, data, status=200, headers=None):
        self.network.side_effect = None
        self.network.return_value = Response(data, status, headers)

    def test_existing_verified_checkpoint_needs_no_download(self):
        self.target.write_bytes(self.weights)
        installer.main()
        self.assertEqual(self.target.read_bytes(), self.weights)
        self.network.assert_not_called()

    def test_corrupt_existing_checkpoint_is_preserved_and_rejected(self):
        self.target.write_bytes(b'x' * len(self.weights))
        with self.assertRaisesRegex(ValueError, 'Existing checkpoint failed'):
            installer.main()
        self.assertEqual(self.target.read_bytes(), b'x' * len(self.weights))
        self.network.assert_not_called()

    def test_changed_configuration_is_rejected_before_download(self):
        (self.directory / 'siglip_config.json').write_bytes(b'{"changed": true}')
        with self.assertRaisesRegex(ValueError, 'Bundled configuration changed'):
            installer.main()
        self.network.assert_not_called()

    def test_partial_download_resumes_only_from_requested_range(self):
        self.partial.write_bytes(self.weights[:5])
        self.response(self.weights[5:], 206, {'Content-Range': f'bytes 5-{len(self.weights)-1}/{len(self.weights)}'})
        installer.main()
        request = self.network.call_args.args[0]
        self.assertEqual(request.get_header('Range'), 'bytes=5-')
        self.assertIn('/resolve/' + installer.REVISION + '/', request.full_url)
        self.assertEqual(self.target.read_bytes(), self.weights)
        self.assertFalse(self.partial.exists())

    def test_server_ignoring_range_restarts_download_without_duplicating_bytes(self):
        self.partial.write_bytes(self.weights[:5])
        self.response(self.weights)
        installer.main()
        self.assertEqual(self.target.read_bytes(), self.weights)

    def test_wrong_range_preserves_partial_and_never_accepts_checkpoint(self):
        self.partial.write_bytes(self.weights[:5])
        self.response(self.weights[5:], 206, {'Content-Range': 'bytes 0-4/24'})
        with self.assertRaisesRegex(ValueError, 'unexpected download range'):
            installer.main()
        self.assertEqual(self.partial.read_bytes(), self.weights[:5])
        self.assertFalse(self.target.exists())

    def test_corrupt_or_truncated_downloads_never_become_final_checkpoint(self):
        for data in (b'x' * len(self.weights), self.weights[:-1]):
            with self.subTest(size=len(data)):
                if self.partial.exists():
                    self.partial.unlink()
                self.response(data)
                with self.assertRaisesRegex(ValueError, 'size or SHA-256 verification'):
                    installer.main()
                self.assertFalse(self.target.exists())
                self.assertEqual(self.partial.read_bytes(), data)

    def test_oversized_download_never_becomes_final_checkpoint(self):
        self.response(self.weights + b'excess')
        with self.assertRaisesRegex(ValueError, 'exceeds expected size'):
            installer.main()
        self.assertFalse(self.target.exists())

    def test_complete_verified_partial_is_promoted_without_download(self):
        self.partial.write_bytes(self.weights)
        installer.main()
        self.assertEqual(self.target.read_bytes(), self.weights)
        self.assertFalse(self.partial.exists())
        self.network.assert_not_called()


class ImageOriginPackagingTests(unittest.TestCase):
    def test_git_roundtrip_preserves_audited_configuration_bytes(self):
        output = ROOT / 'output/validation'
        output.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix='ai-origin-packaging-', dir=output) as temporary:
            directory = Path(temporary).resolve()
            self.assertEqual(directory.parent, output.resolve())
            repository = directory / 'repository'
            repository.mkdir()
            relative = Path('automation/models/ai-origin-broad')
            bundled = ROOT / relative
            source = json.loads((bundled / 'source.json').read_text(encoding='utf-8'))
            names = [*source['configuration_sha256'], 'source.json', 'calibration.json']
            (repository / relative).mkdir(parents=True)
            (repository / '.gitattributes').write_bytes((ROOT / '.gitattributes').read_bytes())
            for name in names:
                (repository / relative / name).write_bytes((bundled / name).read_bytes())

            def git(*args):
                return subprocess.check_output([
                    'git', '-C', str(repository), '-c', 'core.safecrlf=false', *args],
                    stderr=subprocess.PIPE)

            git('init', '-q')
            git('-c', 'core.autocrlf=input', 'add', '.gitattributes', relative.as_posix())
            for name, expected in source['configuration_sha256'].items():
                stored = git('show', ':' + (relative / name).as_posix())
                self.assertEqual(hashlib.sha256(stored).hexdigest(), expected,
                                 f'Git changed the audited bytes: {name}')

            # Verify both Linux-style and Windows-style checkouts of the same index.
            for autocrlf in ('false', 'true'):
                with self.subTest(autocrlf=autocrlf):
                    checkout = directory / ('checkout-' + autocrlf)
                    checkout.mkdir()
                    git('-c', 'core.autocrlf=' + autocrlf, 'checkout-index', '--all',
                        '--prefix=' + checkout.as_posix() + '/')
                    for name, expected in source['configuration_sha256'].items():
                        packaged = (checkout / relative / name).read_bytes()
                        self.assertEqual(hashlib.sha256(packaged).hexdigest(), expected)
                    calibration_bytes = (checkout / relative / 'calibration.json').read_bytes()
                    self.assertEqual(calibration_bytes, (bundled / 'calibration.json').read_bytes())
                    identity = json.loads(calibration_bytes)['model_identity']
                    self.assertEqual(identity['config_sha256'], source['configuration_sha256']['config.json'])
                    self.assertEqual(identity['processor_sha256'], source['configuration_sha256']['preprocessor_config.json'])


if __name__ == '__main__':
    unittest.main()
