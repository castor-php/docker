"""Macros used by the documentation, see https://zensical.org/docs/compatibility/mkdocs/plugins/#macros"""

import os

ROOT = os.path.dirname(os.path.abspath(__file__))


def define_env(env):
    @env.macro
    def markdown(path, start=None, end=None):
        """Include a Markdown file, or only the part between the start and end markers."""
        file = os.path.join(ROOT, path)
        if not os.path.isfile(file):
            raise FileNotFoundError(f'The file "{path}" included in "{env.page.path}" does not exist.')

        with open(file) as f:
            content = f.read()

        for marker in (start, end):
            if marker and marker not in content:
                raise ValueError(f'The marker "{marker}" was not found in "{path}", included in "{env.page.path}".')
        if start:
            content = content.split(start, 1)[1]
        if end:
            content = content.split(end, 1)[0]

        return content
