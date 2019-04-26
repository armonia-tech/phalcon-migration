This is Armonia Tech project.
===================================================================

This project extend from phalcon-devtool migration to run older version's migration file.
 
Installation: 

.. code-block:: bash

    composer require armonia-tech/phalcon-migration


Usage:

To generate a blank migration file or migration file from a table in database:

.. code-block:: bash

    ./vendors/bin/at-phalcon migration generate --table=users --descr=create_table_users --config={path your migration config file}



To run a migration file:

.. code-block:: bash

    ./vendors/bin/at-phalcon migration run --version=1554705232436351_create_table_users --config={path your migration config file}


To rollback a migration file:

.. code-block:: bash

    ./vendors/bin/at-phalcon migration run --version=1554705232436351_create_table_users --config={path your migration config file} --rollback
