# SimpleORM

SimpleORM is a very basic ORM tool for the Cotonti CMF. It consists of only one 
file with one abstract class. It's intended to be placed inside the Cotonti 
system folder, so it can be easily included in modules using `cot_incfile()`. 

## Implementation

First, make sure you include the SimpleORM file:

    require_once cot_incfile('simpleorm');

Your module should have a folder named 'classes', in which you will store your 
model classes. As an example I will use a model named Project, which is stored 
in classes/Project.php:

    class Project extends SimpleORM
    {
        protected $table_name = 'projects';
        protected $columns = array(
            'id' => array(
                'type' => 'int',
                'primary_key' => true,
                'auto_increment' => true,
                'locked' => true
            ),
            'ownerid' => array(
                'type' => 'int',
                'foreign_key' => 'users:user_id',
                'locked' => true
            ),
            'name' => array(
                'type' => 'varchar',
                'length' => 50,
                'unique' => true
            ),
            'desc' => array(
                'type' => 'text'
            ),
            'type' => array(
                'type' => 'varchar',
                'length' => 6
            ),
            'created' => array(
                'type' => 'int',
                'on_insert' => 'NOW()',
                'locked' => true
            ),
            'updated' => array(
                'type' => 'int',
                'on_insert' => 'NOW()',
                'on_update' => 'NOW()',
                'locked' => true
            )
        );
    }

The model class extends SimpleORM, which will make Project inherit all of 
SimpleORM's methods and properties. Since SimpleORM contains all the fancy 
methods, all we need to do here is configure the properties of Project. There 
are two properties we have to configure: $table_name and $columns.

`$table_name` is the name of the database table to store Project objects. A 
common convention is to use the lowercase, plural of the class name, so 
'projects' in this case. SimpleORM will automatically prepend Cotonti's `$db_x` 
to the table name.

$columns is where things get interesting. It is where you configure the database 
columns for the objects. SimpleORM will automatically validate incoming data 
based on the rules set in $columns. This includes variable type checking, 
foreign key constraints and unique values. It also allows you to 'lock' and/or 
'hide' a column from the outside world.

### Adding a project

    $name = cot_import('name', 'P', 'TXT', 50);
    $desc = cot_import('desc', 'P', 'TXT');
    $type = cot_import('type', 'P', 'ALP', 6);

    if ($name && $type)
    {
        $obj = new Project(array(
            'name' => $name,
            'desc' => $desc,
            'type' => $type,
            'ownerid' => $usr['id']
        ));
        if ($obj->insert())
        {
            // succesfully added to database
        }
    }

The `insert()` and `update()` methods are wrappers for a more generic function 
called `save()`. This method can take one argument, which can either be 'insert' 
or 'update'. If you don't pass this argument it will try to update an existing 
record and if that fails try to insert a new record. The save() method has 3 
possible return values: 'added', 'updated' or null. `insert()` and `update()` 
return a boolean.

### Listing projects

To get existing objects from the database, SimpleORM provides three 
'finder methods'. These basically run a SELECT query on the database and return 
rows as objects of the type the finder method was executed on. The three 
variants are `find()`, `findAll()` and `findByPk()`, which respectively will 
return an array of objects matching a set of conditions, return an array of all 
objects or return a single object matching a specific primary key.

Here's an example use case, listing all projects and assigning data columns to 
template tags:

    $projects = Project::findAll($limit, $offset, $order, $way);
    if ($projects)
    {
        foreach ($projects as $project)
        {
            foreach ($project->data() as $key => $value)
            {
                $t->assign(strtoupper($key), $value, 'PROJECT_');
            }
            $t->parse('MAIN.PROJECTS.ROW');
        }
        $t->parse('MAIN.PROJECTS');
    }

This is convenient for lists, but what about a details page of a specific 
object? Here's how to do that:

    $id = cot_import('id', 'G', 'INT');
    $project = Project::findByPk($id);
    foreach ($project->data() as $key => $value)
    {
        $t->assign(strtoupper($key), $value, 'PROJECT_');
    }

### Module setup

SimpleORM provides a way to simplify the install and uninstall files of your 
module. It has two useful methods for setup, `createTable()` and `dropTable()`. 
`createTable()` will create the table based on the configuration provided in the 
model. For example, your myext.install.php file may look like this:

    require_once cot_incfile('simpleorm');

    Project::createTable();
    Milestone::createTable();
    Issue::createTable();

Your myext.uninstall.php will look similar, except that it should call 
`dropTable()` instead of `createTable()`. Of course you might choose not to drop 
the tables upon uninstallation, but that's your choice as a developer.